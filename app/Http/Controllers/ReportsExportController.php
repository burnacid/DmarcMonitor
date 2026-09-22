<?php

namespace App\Http\Controllers;

use App\Models\AggregateReportRecord;
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
     * held in memory at once.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();

        $filters = $request->only([
            'domain_id', 'ip', 'envelope', 'from', 'to', 'search', 'spf_result', 'dkim_result', 'disposition',
        ]);

        return response()->streamDownload(function () use ($user, $filters) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Domain', 'Reporting Org', 'Report ID', 'Period Start', 'Period End',
                'Source IP', 'Count', 'Disposition', 'SPF Result', 'DKIM Result',
                'Header From', 'Envelope From', 'Envelope To', 'PTR Hostname', 'ASN Org', 'Country',
            ]);

            AggregateReportRecord::query()
                ->whereHas('aggregateReport', fn (Builder $query) => $query
                    ->visibleTo($user)
                    ->filter($filters))
                ->with('aggregateReport.domain')
                ->orderBy('aggregate_report_id')
                ->chunk(500, function ($records) use ($handle) {
                    foreach ($records as $record) {
                        $report = $record->aggregateReport;

                        fputcsv($handle, [
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
                        ]);
                    }
                });

            fclose($handle);
        }, 'dmarc-reports-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
