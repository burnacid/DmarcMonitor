<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\AlertEvent;
use App\Models\AlertRule;
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
        $rule = AlertRule::factory()->create(['domain_id' => $domain->id]);
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
        $rule = AlertRule::factory()->create(['domain_id' => $domain->id]);
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
}
