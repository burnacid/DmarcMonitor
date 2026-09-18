<?php

namespace Tests\Unit;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Support\ScheduledTaskTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduledTaskTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_run_records_start_and_finish(): void
    {
        $key = 'test-task-'.Str::random(8);
        ScheduledTaskTracker::attach(Schedule::call(fn () => true)->description('Test task'), $key);

        $event = ScheduledTaskTracker::get($key);
        $event->run(app());

        $run = ScheduledTaskRun::where('task_key', $key)->firstOrFail();
        $this->assertEquals('Test task', $run->label);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertEquals(0, $run->exit_code);
        $this->assertEquals('schedule', $run->triggered_by);
        $this->assertNull($run->triggered_by_user_id);

        // Routine cron-driven runs aren't "who did what" and stay out of the
        // audit trail — only a manual "Run now" click is audited.
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_a_failing_run_records_a_non_zero_exit_code(): void
    {
        $key = 'test-task-'.Str::random(8);
        ScheduledTaskTracker::attach(
            Schedule::call(fn () => throw new \RuntimeException('boom'))->description('Failing task'),
            $key,
        );

        try {
            ScheduledTaskTracker::get($key)->run(app());
        } catch (\RuntimeException) {
            // CallbackEvent rethrows; we only care about what got recorded.
        }

        $run = ScheduledTaskRun::where('task_key', $key)->firstOrFail();
        $this->assertNotNull($run->finished_at);
        $this->assertNotEquals(0, $run->exit_code);
    }

    public function test_run_manually_tags_the_run_with_the_triggering_user(): void
    {
        $key = 'test-task-'.Str::random(8);
        $user = User::factory()->create();
        ScheduledTaskTracker::attach(Schedule::call(fn () => true)->description('Manual task'), $key);

        $result = ScheduledTaskTracker::runManually($key, $user->id);

        $this->assertTrue($result);
        $run = ScheduledTaskRun::where('task_key', $key)->firstOrFail();
        $this->assertEquals('manual', $run->triggered_by);
        $this->assertEquals($user->id, $run->triggered_by_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'scheduled_task.completed',
            'user_id' => $user->id,
        ]);
    }

    public function test_run_manually_throws_for_an_unknown_task_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScheduledTaskTracker::runManually('no-such-task-'.Str::random(8), null);
    }

    public function test_run_manually_is_skipped_while_the_task_is_already_running(): void
    {
        $key = 'test-task-'.Str::random(8);
        $event = ScheduledTaskTracker::attach(
            Schedule::call(fn () => true)->description('Busy task')->withoutOverlapping(),
            $key,
        );

        // Simulate another instance already holding the overlap mutex.
        $event->mutex->create($event);

        $result = ScheduledTaskTracker::runManually($key, null);

        $this->assertFalse($result);
    }
}
