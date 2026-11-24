<?php

namespace App\Jobs;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Services\Analytics\DmarcMetricsService;
use App\Services\Analytics\ExpectedSourceMatcher;
use App\Services\Enrichment\IpEnrichmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnrichReportRecordsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $aggregateReportId,
    ) {}

    public function handle(IpEnrichmentService $service, ExpectedSourceMatcher $matcher): void
    {
        $records = AggregateReportRecord::where('aggregate_report_id', $this->aggregateReportId)->get();

        $service->warmCache($records->pluck('source_ip')->unique()->values()->all());

        foreach ($records->groupBy('source_ip') as $sourceIp => $group) {
            $enrichment = $service->enrich($sourceIp);

            AggregateReportRecord::whereIn('id', $group->pluck('id'))->update([
                'ptr_hostname' => $enrichment['ptr_hostname'],
                'asn' => $enrichment['asn'],
                'asn_org' => $enrichment['asn_org'],
                'country' => $enrichment['country'],
                'enriched_at' => now(),
            ]);
        }

        // Expected sources can match on the hostname or ASN found above.
        $report = AggregateReport::find($this->aggregateReportId);

        if ($report !== null) {
            $matcher->applyToReport($report);
        }

        // Enrichment changes how sources are named and grouped.
        DmarcMetricsService::flushCache();
    }
}
