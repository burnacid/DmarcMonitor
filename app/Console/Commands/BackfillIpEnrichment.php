<?php

namespace App\Console\Commands;

use App\Models\AggregateReportRecord;
use App\Services\Enrichment\IpEnrichmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dmarc:backfill-enrichment {--force : Re-enrich records that already have enrichment data}')]
#[Description('Resolve PTR hostname and ASN/org for existing aggregate report records that are missing enrichment')]
class BackfillIpEnrichment extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IpEnrichmentService $service): int
    {
        $query = AggregateReportRecord::query();

        if (! $this->option('force')) {
            $query->whereNull('enriched_at');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->select('id', 'source_ip')
            ->orderBy('id')
            ->chunkById(200, function ($records) use ($service, $bar) {
                foreach ($records->groupBy('source_ip') as $sourceIp => $group) {
                    $enrichment = $service->enrich($sourceIp);

                    AggregateReportRecord::whereIn('id', $group->pluck('id'))->update([
                        'ptr_hostname' => $enrichment['ptr_hostname'],
                        'asn' => $enrichment['asn'],
                        'asn_org' => $enrichment['asn_org'],
                        'country' => $enrichment['country'],
                        'enriched_at' => now(),
                    ]);

                    $bar->advance($group->count());
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("Backfilled enrichment for {$total} record(s).");

        return self::SUCCESS;
    }
}
