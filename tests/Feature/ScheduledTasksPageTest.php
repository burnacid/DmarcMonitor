<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ScheduledTasksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_editors_cannot_reach_the_scheduled_tasks_page(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->get('/admin/scheduled-tasks')->assertForbidden();
    }

    public function test_admins_can_reach_the_scheduled_tasks_page(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/admin/scheduled-tasks')->assertOk();
    }

    public function test_it_lists_every_tracked_task(): void
    {
        $admin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.scheduled-tasks')
            ->assertSee('IMAP mailbox poll')
            ->assertSee('Microsoft 365 mailbox poll')
            ->assertSee('Import local .eml reports')
            ->assertSee('Evaluate alert rules')
            ->assertSee('Update GeoIP databases')
            ->assertSee('Prune old data')
            ->assertSee('Check domain DNS records')
            ->assertSee('Never run');
    }

    public function test_run_now_dispatches_the_job_in_the_background(): void
    {
        Bus::fake();
        $admin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.scheduled-tasks')
            ->call('runNow', 'check-dns')
            ->assertSee('Started in the background');

        Bus::assertDispatched(RunScheduledTaskJob::class);
    }

    public function test_a_recorded_run_is_reflected_on_the_page(): void
    {
        // Seeded directly rather than actually executing the real scheduled
        // Event: Event::run() shells out `php artisan ...` as a real
        // subprocess against whatever the OS environment/database is, which
        // would be unsafe to trigger from a test.
        $admin = User::factory()->create();

        ScheduledTaskRun::create([
            'task_key' => 'check-dns',
            'label' => 'Check domain DNS records',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'exit_code' => 0,
            'triggered_by' => 'schedule',
        ]);

        Volt::actingAs($admin)->test('admin.scheduled-tasks')
            ->assertSee('Success');
    }
}
