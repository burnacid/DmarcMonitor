<?php

namespace App\Jobs;

use App\Support\ScheduledTaskTracker;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Intentionally not a ShouldQueue job: dispatched with ->afterResponse() so a
 * "Run now" click returns immediately without waiting on the cron-drained
 * queue (see routes/console.php's `queue:work --stop-when-empty` comment —
 * this app has no persistent worker, so a real queued job could sit for up
 * to 5 minutes before running, which defeats the point of "Run now").
 */
class RunScheduledTaskJob
{
    use Dispatchable;

    public function __construct(
        private readonly string $taskKey,
        private readonly ?int $userId,
    ) {}

    public function handle(): void
    {
        ScheduledTaskTracker::ensureBootstrapped();
        ScheduledTaskTracker::runManually($this->taskKey, $this->userId);
    }
}
