<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
