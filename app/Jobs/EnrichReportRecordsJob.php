<?php

namespace App\Jobs;

use App\Models\AggregateReportRecord;
use App\Services\Enrichment\IpEnrichmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnrichReportRecordsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $aggregateReportId,
    ) {}

    public function handle(IpEnrichmentService $service): void
    {
        $records = AggregateReportRecord::where('aggregate_report_id', $this->aggregateReportId)->get();

        foreach ($records->groupBy('source_ip') as $sourceIp => $group) {
            $enrichment = $service->enrich($sourceIp);

            AggregateReportRecord::whereIn('id', $group->pluck('id'))->update([
                'ptr_hostname' => $enrichment['ptr_hostname'],
                'asn' => $enrichment['asn'],
                'asn_org' => $enrichment['asn_org'],
                'enriched_at' => now(),
            ]);
        }
    }
}
