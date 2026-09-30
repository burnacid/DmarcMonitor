<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\ImapAccount;
use App\Models\Microsoft365MailAccount;
use App\Services\Graph\GraphIngestionService;
use App\Services\Imap\ImapIngestionService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CleanupOldDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_aggregate_reports_and_their_records_past_retention(): void
    {
        config(['dmarc.retention_days' => 400]);

        $old = AggregateReport::factory()->create(['date_range_begin' => now()->subDays(401)]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $old->id]);

        $this->artisan('dmarc:cleanup');

        $this->assertDatabaseMissing('aggregate_reports', ['id' => $old->id]);
        $this->assertDatabaseCount('aggregate_report_records', 0);
    }

    public function test_keeps_aggregate_reports_within_retention(): void
    {
        config(['dmarc.retention_days' => 400]);

        $recent = AggregateReport::factory()->create(['date_range_begin' => now()->subDays(10)]);

        $this->artisan('dmarc:cleanup');

        $this->assertDatabaseHas('aggregate_reports', ['id' => $recent->id]);
    }

    public function test_deletes_resolved_alert_events_past_retention(): void
    {
        config(['dmarc.retention_days' => 400]);

        $domain = Domain::factory()->create();
        $rule = AlertRule::factory()->create();
        $oldResolved = AlertEvent::create([
            'alert_rule_id' => $rule->id,
            'domain_id' => $domain->id,
            'fired_at' => now()->subDays(410),
            'resolved_at' => now()->subDays(401),
            'dedup_key' => 'old-resolved',
        ]);

        $this->artisan('dmarc:cleanup');

        $this->assertDatabaseMissing('alert_events', ['id' => $oldResolved->id]);
    }

    public function test_keeps_open_alert_events_regardless_of_age(): void
    {
        config(['dmarc.retention_days' => 400]);

        $domain = Domain::factory()->create();
        $rule = AlertRule::factory()->create();
        $oldOpen = AlertEvent::create([
            'alert_rule_id' => $rule->id,
            'domain_id' => $domain->id,
            'fired_at' => now()->subDays(410),
            'resolved_at' => null,
            'dedup_key' => 'old-open',
        ]);

        $this->artisan('dmarc:cleanup');

        $this->assertDatabaseHas('alert_events', ['id' => $oldOpen->id]);
    }

    public function test_does_nothing_when_retention_is_not_configured(): void
    {
        config(['dmarc.retention_days' => null]);

        $old = AggregateReport::factory()->create(['date_range_begin' => now()->subDays(1000)]);

        $this->artisan('dmarc:cleanup');

        $this->assertDatabaseHas('aggregate_reports', ['id' => $old->id]);
    }

    public function test_permanently_deletes_trashed_domains_past_retention(): void
    {
        config(['dmarc.retention_days' => 400]);

        $oldTrashed = Domain::factory()->create();
        $oldTrashed->delete();
        $oldTrashed->forceFill(['deleted_at' => now()->subDays(401)])->saveQuietly();

        $this->artisan('dmarc:cleanup');

        $this->assertDatabaseMissing('domains', ['id' => $oldTrashed->id]);
    }

    public function test_keeps_trashed_domains_within_retention(): void
    {
        config(['dmarc.retention_days' => 400]);

        $recentlyTrashed = Domain::factory()->create();
        $recentlyTrashed->delete();

        $this->artisan('dmarc:cleanup');

        $this->assertDatabaseHas('domains', ['id' => $recentlyTrashed->id]);
    }

    public function test_prunes_old_processed_and_failed_eml_files_past_retention(): void
    {
        config(['dmarc.retention_days' => 400]);

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eml-cleanup-test-'.uniqid();
        mkdir($base.'/processed', 0755, true);
        mkdir($base.'/failed', 0755, true);
        config(['dmarc.eml_import_path' => $base]);

        $oldProcessed = $base.'/processed/old.eml';
        $recentProcessed = $base.'/processed/recent.eml';
        $oldFailed = $base.'/failed/old.eml';

        file_put_contents($oldProcessed, 'x');
        file_put_contents($recentProcessed, 'x');
        file_put_contents($oldFailed, 'x');

        touch($oldProcessed, now()->subDays(401)->timestamp);
        touch($oldFailed, now()->subDays(401)->timestamp);
        touch($recentProcessed, now()->subDays(10)->timestamp);

        $this->artisan('dmarc:cleanup');

        $this->assertFileDoesNotExist($oldProcessed);
        $this->assertFileDoesNotExist($oldFailed);
        $this->assertFileExists($recentProcessed);
    }

    public function test_prunes_old_audit_log_entries_past_retention(): void
    {
        config(['dmarc.retention_days' => 400]);

        $old = AuditLog::create(['action' => 'domain.created', 'description' => 'old entry']);
        $old->forceFill(['created_at' => now()->subDays(401)])->saveQuietly();

        $recent = AuditLog::create(['action' => 'domain.created', 'description' => 'recent entry']);
        $recent->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        $this->artisan('dmarc:cleanup');

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
    }

    public function test_deletes_old_mailbox_messages_only_for_active_accounts_that_opted_in(): void
    {
        config(['dmarc.retention_days' => 400]);

        $imapOptedIn = ImapAccount::factory()->create(['delete_old_messages' => true]);
        ImapAccount::factory()->create(['delete_old_messages' => false]);
        ImapAccount::factory()->create(['delete_old_messages' => true, 'is_active' => false]);
        $microsoft365OptedIn = Microsoft365MailAccount::factory()->create(['delete_old_messages' => true]);
        Microsoft365MailAccount::factory()->create(['delete_old_messages' => false]);

        $this->mock(ImapIngestionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('pruneMessagesOlderThan')
            ->once()
            ->withArgs(fn (ImapAccount $account, CarbonInterface $cutoff) => $account->is($imapOptedIn) && $cutoff->isSameDay(now()->subDays(400)))
            ->andReturn(2));

        $this->mock(GraphIngestionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('pruneMessagesOlderThan')
            ->once()
            ->withArgs(fn (Microsoft365MailAccount $account, CarbonInterface $cutoff) => $account->is($microsoft365OptedIn) && $cutoff->isSameDay(now()->subDays(400)))
            ->andReturn(3));

        $this->artisan('dmarc:cleanup')
            ->expectsOutputToContain('Deleted 5 mailbox message(s) older than 400 days.')
            ->assertSuccessful();
    }

    public function test_a_failing_mailbox_does_not_stop_the_others_from_being_cleaned_up(): void
    {
        config(['dmarc.retention_days' => 400]);

        ImapAccount::factory()->create(['delete_old_messages' => true, 'label' => 'Broken mailbox']);
        Microsoft365MailAccount::factory()->create(['delete_old_messages' => true]);

        $this->mock(ImapIngestionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('pruneMessagesOlderThan')
            ->andThrow(new RuntimeException('Authentication failed')));

        $this->mock(GraphIngestionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('pruneMessagesOlderThan')
            ->once()
            ->andReturn(3));

        $this->artisan('dmarc:cleanup')
            ->expectsOutputToContain('Deleting old messages failed for IMAP account [Broken mailbox]: Authentication failed')
            ->expectsOutputToContain('Deleted 3 mailbox message(s) older than 400 days.')
            ->assertSuccessful();
    }
}
