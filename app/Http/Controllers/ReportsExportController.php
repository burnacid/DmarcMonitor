<?php

namespace App\Http\Controllers;

use App\Models\AggregateReportRecord;
use App\Support\CsvRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsExportController extends Controller
{
    /**
     * Stream a CSV of every record behind the reports the user's current
     * filters match — one row per source IP/record, not per report, since
     * that's the granularity someone reviewing sending sources actually
     * wants. Streamed and chunked so a large export doesn't have to be
     * held in memory at once. From the Records tab (view=records) only
     * the records that match the filters themselves are exported.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();

        $filters = $request->only([
            'domain_id', 'ip', 'envelope', 'from', 'to', 'search', 'spf_result', 'dkim_result', 'disposition',
        ]);

        $records = $request->query('view') === 'records'
            ? AggregateReportRecord::query()->filter($filters, $user)
            : AggregateReportRecord::query()->whereHas('aggregateReport', fn (Builder $query) => $query
                ->visibleTo($user)
                ->filter($filters));

        return response()->streamDownload(function () use ($records) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Domain', 'Reporting Org', 'Report ID', 'Period Start', 'Period End',
                'Source IP', 'Count', 'Disposition', 'SPF Result', 'DKIM Result',
                'Header From', 'Envelope From', 'Envelope To', 'PTR Hostname', 'ASN Org', 'Country',
            ]);

            $records
                ->with('aggregateReport.domain')
                ->orderBy('aggregate_report_id')
                ->chunk(500, function ($records) use ($handle) {
                    foreach ($records as $record) {
                        $report = $record->aggregateReport;

                        fputcsv($handle, CsvRow::safe([
                            $report->domain?->fqdn,
                            $report->org_name,
                            $report->report_id,
                            $report->date_range_begin->toDateString(),
                            $report->date_range_end->toDateString(),
                            $record->source_ip,
                            $record->count,
                            $record->disposition,
                            $record->spf_result,
                            $record->dkim_result,
                            $record->header_from,
                            $record->envelope_from,
                            $record->envelope_to,
                            $record->ptr_hostname,
                            $record->asn_org,
                            $record->country,
                        ]));
                    }
                });

            fclose($handle);
        }, 'dmarc-reports-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
