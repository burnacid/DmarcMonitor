<?php

namespace App\Services\Dmarc;

use App\Models\Domain;
use App\Models\ForensicReport;
use App\Models\ImapAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ForensicReportParser
{
    /**
     * Delivery-Result values the enum column accepts; anything else observed
     * in the wild falls back to "other" rather than failing the whole report.
     */
    private const array VALID_DELIVERY_RESULTS = ['delivered', 'spam', 'policy', 'reject', 'other'];

    /**
     * Parse the machine-readable part of an RFC 6591 forensic (ARF) report and
     * persist it, idempotently keyed on the IMAP account + message.
     */
    public function parseFromMessage(
        string $feedbackReportText,
        ?ImapAccount $imapAccount = null,
        ?string $rawMessagePath = null,
        ?string $messageUid = null,
        ?string $subject = null,
    ): ForensicReport {
        $fields = $this->parseFields($feedbackReportText);

        $reportedDomain = $fields['reported-domain'] ?? $this->domainFromEmail($fields['original-mail-from'] ?? null);

        if ($reportedDomain === null) {
            throw new \RuntimeException('Forensic report has neither a Reported-Domain nor a usable Original-Mail-From field.');
        }

        $authFailures = isset($fields['auth-failure'])
            ? array_map('trim', explode(',', strtolower($fields['auth-failure'])))
            : [];

        return DB::transaction(function () use ($fields, $reportedDomain, $authFailures, $imapAccount, $rawMessagePath, $messageUid, $subject) {
            $domain = $this->resolveDomain($reportedDomain);

            return ForensicReport::updateOrCreate(
                [
                    'imap_account_id' => $imapAccount?->id,
                    'message_uid' => $messageUid,
                ],
                [
                    'domain_id' => $domain->id,
                    'arrival_date' => $this->parseDate($fields['arrival-date'] ?? null),
                    'source_ip' => $fields['source-ip'] ?? null,
                    'original_envelope_id' => $fields['original-envelope-id'] ?? null,
                    'authentication_results' => $fields['authentication-results'] ?? null,
                    'delivery_result' => $this->normalizeDeliveryResult($fields['delivery-result'] ?? null),
                    'header_from' => $reportedDomain,
                    'envelope_from' => $fields['original-mail-from'] ?? null,
                    'envelope_to' => $fields['original-rcpt-to'] ?? null,
                    'dkim_domain' => $fields['dkim-domain'] ?? null,
                    'dkim_result' => in_array('dkim', $authFailures, true) ? 'fail' : null,
                    'spf_domain' => in_array('spf', $authFailures, true) ? $this->domainFromEmail($fields['original-mail-from'] ?? null) : null,
                    'spf_result' => in_array('spf', $authFailures, true) ? 'fail' : null,
                    'subject' => $subject,
                    'raw_message_path' => $rawMessagePath,
                    'processed_at' => now(),
                ],
            );
        });
    }

    /**
     * @return array<string, string> Lowercased field names to their (trimmed) values.
     */
    private function parseFields(string $feedbackReportText): array
    {
        $fields = [];

        foreach (preg_split('/\r\n|\r|\n/', $feedbackReportText) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $fields[strtolower(trim($key))] = trim($value);
        }

        return $fields;
    }

    private function domainFromEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        return strtolower(trim(substr($email, strrpos($email, '@') + 1), " \t\n\r\0\x0B<>"));
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDeliveryResult(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, self::VALID_DELIVERY_RESULTS, true) ? $value : 'other';
    }

    /**
     * Resolve the domain the report applies to, auto-creating it as
     * inactive/unassigned when no matching domain is configured yet.
     */
    private function resolveDomain(string $fqdn): Domain
    {
        return Domain::firstOrCreate(
            ['fqdn' => $fqdn],
            ['is_active' => false],
        );
    }
}
