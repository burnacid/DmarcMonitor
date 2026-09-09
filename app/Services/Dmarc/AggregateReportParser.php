<?php

namespace App\Services\Dmarc;

use App\Jobs\EnrichReportRecordsJob;
use App\Models\AggregateReport;
use App\Models\Domain;
use App\Models\ImapAccount;
use App\Support\CompressedFileReader;
use Avvertix\DmarcReportParser\Data\DmarcReport;
use Avvertix\DmarcReportParser\Data\Record;
use Avvertix\DmarcReportParser\DmarcReportParser;
use Illuminate\Support\Facades\DB;

class AggregateReportParser
{
    public function __construct(
        private readonly DmarcReportParser $parser = new DmarcReportParser,
    ) {}

    /**
     * Parse an aggregate report file and persist it, idempotently.
     *
     * Returns the AggregateReport, or null if the file could not be parsed.
     */
    public function parseFile(
        string $path,
        ?ImapAccount $imapAccount = null,
        ?string $rawXmlPath = null,
        ?string $messageUid = null,
    ): ?AggregateReport {
        $xml = CompressedFileReader::read($path);
        $report = $this->parser->fromString($this->normalizeEnumCase($this->normalizeVersion($xml)));

        return $this->store($report, $imapAccount, $rawXmlPath, $messageUid);
    }

    /**
     * Some senders (Amazon SES observed in the wild) emit a non-standard
     * <version> value — e.g. "0.1" — despite the report otherwise being a
     * perfectly parseable RFC 7489 aggregate report. The underlying parser
     * strictly rejects anything but "1.0", so normalize it here rather than
     * losing real reports over a cosmetic version mismatch.
     */
    private function normalizeVersion(string $xml): string
    {
        return preg_replace('/<version>.*?<\/version>/', '<version>1.0</version>', $xml, 1) ?? $xml;
    }

    /**
     * The underlying parser backs disposition/DKIM/SPF results with strict
     * lowercase-only enums, but not every sender complies — KDDI/au.com has
     * been observed sending <result>Fail</result>. Lowercase the handful of
     * tags that carry those enum values wherever they appear as plain text.
     *
     * The `[^<]*` content class is what keeps this safe: `dkim` and `spf` are
     * also used as *container* tags elsewhere in the schema (auth_results),
     * and a container's content always includes a `<` from its child
     * elements, so this only ever matches the scalar policy_evaluated/
     * auth_results.result values it's meant to, never those containers.
     */
    private function normalizeEnumCase(string $xml): string
    {
        return preg_replace_callback(
            '/<(dkim|spf|disposition|result)>([^<]*)<\/\1>/',
            fn (array $matches) => '<'.$matches[1].'>'.strtolower($matches[2]).'</'.$matches[1].'>',
            $xml,
        ) ?? $xml;
    }

    public function store(
        DmarcReport $report,
        ?ImapAccount $imapAccount = null,
        ?string $rawXmlPath = null,
        ?string $messageUid = null,
    ): AggregateReport {
        $aggregateReport = DB::transaction(function () use ($report, $imapAccount, $rawXmlPath, $messageUid) {
            $domain = $this->resolveDomain($report->publishedPolicy->domain);

            $aggregateReport = AggregateReport::updateOrCreate(
                [
                    'domain_id' => $domain->id,
                    'report_id' => $report->report_id,
                    'org_name' => $report->org_name,
                ],
                [
                    'imap_account_id' => $imapAccount?->id,
                    'email' => $report->email,
                    'date_range_begin' => $report->date_range->begin,
                    'date_range_end' => $report->date_range->end,
                    'policy_domain' => $report->publishedPolicy->domain,
                    'policy_adkim' => $report->publishedPolicy->adkim?->value ?? 'r',
                    'policy_aspf' => $report->publishedPolicy->aspf?->value ?? 'r',
                    'policy_p' => $report->publishedPolicy->p->value,
                    'policy_sp' => $report->publishedPolicy->sp?->value,
                    'policy_pct' => $report->publishedPolicy->pct ?? 100,
                    'raw_xml_path' => $rawXmlPath,
                    'message_uid' => $messageUid,
                    'processed_at' => now(),
                ]
            );

            $aggregateReport->records()->delete();

            foreach ($report->records as $record) {
                $this->storeRecord($aggregateReport, $record);
            }

            return $aggregateReport;
        });

        EnrichReportRecordsJob::dispatch($aggregateReport->id);

        return $aggregateReport;
    }

    private function storeRecord(AggregateReport $aggregateReport, Record $record): void
    {
        $dkim = $record->auth_results->dkim[0] ?? null;
        $spf = $record->auth_results->spf[0] ?? null;

        $aggregateReport->records()->create([
            'source_ip' => $record->row->source_ip,
            'count' => $record->row->count,
            'disposition' => $record->row->policy_evaluated->disposition->value,
            'dkim_result' => $record->row->policy_evaluated->dkim->value,
            'spf_result' => $record->row->policy_evaluated->spf->value,
            'header_from' => $record->identifiers->header_from,
            'envelope_from' => $record->identifiers->envelope_from,
            'envelope_to' => $record->identifiers->envelope_to,
            'dkim_domain' => $dkim?->domain,
            'dkim_selector' => $dkim?->selector,
            'dkim_auth_result' => $dkim?->result->value,
            'spf_domain' => $spf?->domain,
            'spf_scope' => $spf?->scope,
            'spf_auth_result' => $spf?->result->value,
        ]);
    }

    /**
     * Resolve the domain the published policy applies to, auto-creating it as
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
