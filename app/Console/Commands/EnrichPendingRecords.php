<?php

namespace App\Console\Commands;

use App\Jobs\EnrichReportRecordsJob;
use App\Models\AggregateReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dmarc:enrich-pending')]
#[Description('Dispatch enrichment jobs for any report records still missing enrichment (catch-up sweep)')]
class EnrichPendingRecords extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dispatched = 0;

        AggregateReport::whereHas('records', fn ($q) => $q->whereNull('enriched_at'))
            ->select('id')
            ->orderBy('id')
            ->chunk(200, function ($reports) use (&$dispatched): void {
                foreach ($reports as $report) {
                    EnrichReportRecordsJob::dispatch($report->id);
                    $dispatched++;
                }
            });

        if ($dispatched === 0) {
            $this->info('All records are already enriched.');
        } else {
            $this->info("Dispatched {$dispatched} enrichment job(s).");
        }

        return self::SUCCESS;
    }
}
