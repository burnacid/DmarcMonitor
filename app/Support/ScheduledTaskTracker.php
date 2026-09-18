<?php

namespace App\Support;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Console\Kernel;

class ScheduledTaskTracker
{
    /**
     * @var array<string, Event>
     */
    private static array $registry = [];

    private static bool $bootstrapped = false;

    private static bool $manualTrigger = false;

    private static ?int $manualUserId = null;

    /**
     * Wire a scheduled event to record its start/finish into scheduled_task_runs,
     * and remember it under $key so it can be looked up and re-run on demand.
     */
    public static function attach(Event $event, string $key): Event
    {
        self::$registry[$key] = $event;

        return $event
            ->before(function () use ($event, $key) {
                ScheduledTaskRun::query()->updateOrCreate(
                    ['task_key' => $key],
                    [
                        'label' => $event->description ?? $key,
                        'started_at' => now(),
                        'finished_at' => null,
                        'exit_code' => null,
                        'triggered_by' => self::$manualTrigger ? 'manual' : 'schedule',
                        'triggered_by_user_id' => self::$manualTrigger ? self::$manualUserId : null,
                    ],
                );
            })
            ->after(function () use ($event, $key) {
                ScheduledTaskRun::query()->where('task_key', $key)->update([
                    'finished_at' => now(),
                    'exit_code' => $event->exitCode,
                ]);

                // Routine cron-driven runs aren't a "who did what" event and stay
                // out of the audit trail — they're already fully visible on the
                // Scheduled Tasks page via scheduled_task_runs above. Only an
                // admin's manual "Run now" click is worth auditing.
                if (self::$manualTrigger) {
                    AuditLogger::record(
                        action: $event->exitCode === 0 ? 'scheduled_task.completed' : 'scheduled_task.failed',
                        description: ($event->description ?? $key).' run manually (exit code '.$event->exitCode.')',
                        organisationId: null,
                        userId: self::$manualUserId,
                        context: ['task_key' => $key, 'exit_code' => $event->exitCode],
                    );
                }
            });
    }

    public static function get(string $key): ?Event
    {
        return self::$registry[$key] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::$registry);
    }

    /**
     * Run a registered task outside of its normal schedule (e.g. an admin's
     * "Run now" click), through the exact same Event::run() the real
     * scheduler uses — same withoutOverlapping mutex, same before/after hooks.
     *
     * Returns false if the task was skipped because it was already running.
     */
    public static function runManually(string $key, ?int $userId): bool
    {
        $event = self::get($key);

        if ($event === null) {
            throw new \InvalidArgumentException("Unknown scheduled task [{$key}].");
        }

        self::$manualTrigger = true;
        self::$manualUserId = $userId;

        try {
            $event->run(app());

            return ! $event->skippedBecauseOverlapping;
        } finally {
            self::$manualTrigger = false;
            self::$manualUserId = null;
        }
    }

    /**
     * routes/console.php only runs when the console kernel bootstraps
     * (normal `php artisan ...` execution) — a plain web request never
     * touches it. Force it once so the schedule/registry are populated.
     */
    public static function ensureBootstrapped(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        app(Kernel::class)->bootstrap();

        self::$bootstrapped = true;
    }
}
