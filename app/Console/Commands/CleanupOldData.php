<?php

namespace App\Console\Commands;

use App\Models\AggregateReport;
use App\Models\AlertEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('dmarc:cleanup')]
#[Description('Prune aggregate reports and resolved alert events older than the configured retention period')]
class CleanupOldData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = config('dmarc.retention_days');

        if ($days === null) {
            $this->comment('No DATA_RETENTION_DAYS configured — skipping cleanup.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays((int) $days);

        // Aggregate report records cascade-delete with their parent report
        // (foreign key constraint), so deleting the reports is enough.
        $reports = AggregateReport::where('date_range_begin', '<', $cutoff);
        $reportCount = $reports->count();
        $reports->delete();

        $events = AlertEvent::whereNotNull('resolved_at')->where('resolved_at', '<', $cutoff);
        $eventCount = $events->count();
        $events->delete();

        $this->info("Deleted {$reportCount} aggregate report(s) older than {$days} days.");
        $this->info("Deleted {$eventCount} resolved alert event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
