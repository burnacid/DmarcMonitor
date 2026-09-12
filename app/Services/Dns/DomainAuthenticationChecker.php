<?php

namespace App\Services\Dns;

use App\Models\AggregateReportRecord;
use App\Models\Domain;
use Illuminate\Support\Facades\Log;
use Throwable;

class DomainAuthenticationChecker
{
    /**
     * @var callable(string): array<int, string>
     */
    private $txtResolver;

    /**
     * @param  (callable(string): array<int, string>)|null  $txtResolver  Defaults to a real
     *                                                                    dns_get_record() TXT lookup;
     *                                                                    injectable so tests never hit real DNS.
     */
    public function __construct(?callable $txtResolver = null)
    {
        $this->txtResolver = $txtResolver ?? function (string $hostname): array {
            $records = @dns_get_record($hostname, DNS_TXT) ?: [];

            return collect($records)
                ->map(fn (array $record) => $record['txt'] ?? implode('', $record['entries'] ?? []))
                ->filter()
                ->values()
                ->all();
        };
    }

    /**
     * Check a domain's DMARC, SPF, and DKIM DNS records, returning the
     * column values to persist (does not save anything itself).
     *
     * @return array<string, mixed>
     */
    public function check(Domain $domain): array
    {
        return array_merge(
            $this->checkDmarc($domain->fqdn),
            $this->checkSpf($domain->fqdn),
            $this->checkDkim($domain),
            ['dns_checked_at' => now()],
        );
    }

    public function checkAndStore(Domain $domain): Domain
    {
        $domain->update($this->check($domain));

        return $domain->refresh();
    }

    /**
     * @return array{dmarc_status: string, dmarc_record: ?string}
     */
    private function checkDmarc(string $fqdn): array
    {
        $record = $this->firstMatching("_dmarc.{$fqdn}", fn (string $txt) => str_starts_with(strtolower($txt), 'v=dmarc1'));

        if ($record === null) {
            return ['dmarc_status' => 'missing', 'dmarc_record' => null];
        }

        preg_match('/p=(\w+)/i', $record, $matches);
        $policy = strtolower($matches[1] ?? '');

        $status = match (true) {
            in_array($policy, ['quarantine', 'reject'], true) => 'valid',
            $policy === 'none' => 'weak',
            default => 'missing',
        };

        return ['dmarc_status' => $status, 'dmarc_record' => $record];
    }

    /**
     * @return array{spf_status: string, spf_record: ?string}
     */
    private function checkSpf(string $fqdn): array
    {
        $record = $this->firstMatching($fqdn, fn (string $txt) => str_starts_with(strtolower($txt), 'v=spf1'));

        return [
            'spf_status' => $record !== null ? 'valid' : 'missing',
            'spf_record' => $record,
        ];
    }

    /**
     * @return array{dkim_status: string, dkim_selector: ?string, dkim_record: ?string}
     */
    private function checkDkim(Domain $domain): array
    {
        $selectors = AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->where('aggregate_reports.domain_id', $domain->id)
            ->where('aggregate_report_records.dkim_domain', $domain->fqdn)
            ->whereNotNull('aggregate_report_records.dkim_selector')
            ->distinct()
            ->pluck('aggregate_report_records.dkim_selector');

        if ($selectors->isEmpty()) {
            return ['dkim_status' => 'unknown', 'dkim_selector' => null, 'dkim_record' => null];
        }

        foreach ($selectors as $selector) {
            $record = $this->firstMatching(
                "{$selector}._domainkey.{$domain->fqdn}",
                fn (string $txt) => str_contains(strtolower($txt), 'v=dkim1') || str_contains($txt, 'p='),
            );

            if ($record !== null) {
                return ['dkim_status' => 'valid', 'dkim_selector' => $selector, 'dkim_record' => $record];
            }
        }

        return ['dkim_status' => 'missing', 'dkim_selector' => $selectors->first(), 'dkim_record' => null];
    }

    /**
     * @param  callable(string): bool  $matches
     */
    private function firstMatching(string $hostname, callable $matches): ?string
    {
        try {
            $records = ($this->txtResolver)($hostname);
        } catch (Throwable $e) {
            Log::warning("DNS TXT lookup failed for [{$hostname}]: {$e->getMessage()}");

            return null;
        }

        foreach ($records as $record) {
            if ($matches($record)) {
                return $record;
            }
        }

        return null;
    }
}
