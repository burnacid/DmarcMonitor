<?php

namespace Tests\Unit;

use App\Mail\AlertTriggered;
use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Services\Alerts\AlertEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pass_rate_drop_rule_fires_when_below_threshold(): void
    {
        Mail::fake();

        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subHours(2),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'count' => 10,
            'dkim_result' => 'fail',
            'spf_result' => 'fail',
        ]);

        $rule = AlertRule::factory()->create([
            'domain_id' => $domain->id,
            'type' => 'pass_rate_drop',
            'threshold_percent' => 95,
            'notify_emails' => ['ops@example.com'],
        ]);

        app(AlertEvaluationService::class)->evaluate($rule);

        $this->assertDatabaseCount('alert_events', 1);
        $event = AlertEvent::first();
        $this->assertEquals($domain->id, $event->domain_id);
        $this->assertNull($event->resolved_at);
        $this->assertEquals(['email'], $event->notified_channels);

        Mail::assertQueued(AlertTriggered::class);
    }

    public function test_pass_rate_drop_rule_does_not_refire_while_condition_holds(): void
    {
        Mail::fake();

        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subHours(2),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'count' => 10,
            'dkim_result' => 'fail',
            'spf_result' => 'fail',
        ]);

        $rule = AlertRule::factory()->create([
            'domain_id' => $domain->id,
            'type' => 'pass_rate_drop',
            'threshold_percent' => 95,
        ]);

        $service = app(AlertEvaluationService::class);
        $service->evaluate($rule);
        $service->evaluate($rule);

        $this->assertDatabaseCount('alert_events', 1);
    }

    public function test_pass_rate_drop_rule_auto_resolves_once_metric_recovers(): void
    {
        Mail::fake();

        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subHours(2),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'count' => 10,
            'dkim_result' => 'fail',
            'spf_result' => 'fail',
        ]);

        $rule = AlertRule::factory()->create([
            'domain_id' => $domain->id,
            'type' => 'pass_rate_drop',
            'threshold_percent' => 95,
        ]);

        $service = app(AlertEvaluationService::class);
        $service->evaluate($rule);

        // A flood of passing mail within the same window pulls the overall
        // rate back above the threshold.
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'count' => 1000,
            'dkim_result' => 'pass',
            'spf_result' => 'pass',
        ]);

        $service->evaluate($rule);

        $this->assertDatabaseCount('alert_events', 1);
        $this->assertNotNull(AlertEvent::first()->resolved_at);
    }

    public function test_spf_fail_spike_rule_fires_when_fail_rate_exceeds_threshold(): void
    {
        Mail::fake();

        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subHours(2),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'count' => 10,
            'dkim_result' => 'pass',
            'spf_result' => 'fail',
        ]);

        $rule = AlertRule::factory()->create([
            'domain_id' => $domain->id,
            'type' => 'spf_fail_spike',
            'threshold_percent' => 10,
        ]);

        app(AlertEvaluationService::class)->evaluate($rule);

        $this->assertDatabaseCount('alert_events', 1);
        $this->assertEquals('spf_pass_pct', AlertEvent::first()->details['metric']);
    }

    public function test_new_source_detected_rule_fires_once_per_new_ip(): void
    {
        Mail::fake();

        $domain = Domain::factory()->create();

        $baselineReport = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subDays(10),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $baselineReport->id,
            'source_ip' => '203.0.113.1',
        ]);

        $recentReport = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subHours(1),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $recentReport->id,
            'source_ip' => '203.0.113.1',
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $recentReport->id,
            'source_ip' => '198.51.100.9',
        ]);

        $rule = AlertRule::factory()->create([
            'domain_id' => $domain->id,
            'type' => 'new_source_detected',
            'threshold_percent' => null,
        ]);

        $service = app(AlertEvaluationService::class);
        $service->evaluate($rule);
        $service->evaluate($rule);

        $this->assertDatabaseCount('alert_events', 1);
        $this->assertEquals('198.51.100.9', AlertEvent::first()->details['source_ip']);
    }

    public function test_new_domain_discovered_rule_fires_once_per_inactive_domain(): void
    {
        Mail::fake();

        $discovered = Domain::factory()->create(['is_active' => false, 'fqdn' => 'unexpected.example']);
        AggregateReport::factory()->create(['domain_id' => $discovered->id]);

        // An inactive domain with no reports yet isn't "discovered" — nothing
        // has actually come in for it.
        Domain::factory()->create(['is_active' => false, 'fqdn' => 'placeholder.example']);

        // An active domain, even with reports, isn't a candidate either.
        $active = Domain::factory()->create(['is_active' => true]);
        AggregateReport::factory()->create(['domain_id' => $active->id]);

        $rule = AlertRule::factory()->create([
            'domain_id' => null,
            'type' => 'new_domain_discovered',
            'threshold_percent' => null,
        ]);

        $service = app(AlertEvaluationService::class);
        $service->evaluate($rule);
        $service->evaluate($rule);

        $this->assertDatabaseCount('alert_events', 1);
        $event = AlertEvent::first();
        $this->assertEquals($discovered->id, $event->domain_id);
        $this->assertEquals('unexpected.example', $event->details['domain']);

        Mail::assertQueued(AlertTriggered::class);
    }

    public function test_rule_is_skipped_when_there_is_no_volume_in_the_window(): void
    {
        Mail::fake();

        $domain = Domain::factory()->create();
        $rule = AlertRule::factory()->create([
            'domain_id' => $domain->id,
            'type' => 'pass_rate_drop',
            'threshold_percent' => 95,
        ]);

        app(AlertEvaluationService::class)->evaluate($rule);

        $this->assertDatabaseCount('alert_events', 0);
    }

    public function test_webhook_channel_posts_event_payload(): void
    {
        Http::fake(['https://example.com/hook' => Http::response('', 200)]);

        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subHours(2),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'count' => 10,
            'dkim_result' => 'fail',
            'spf_result' => 'fail',
        ]);

        $rule = AlertRule::factory()->create([
            'domain_id' => $domain->id,
            'type' => 'pass_rate_drop',
            'threshold_percent' => 95,
            'channels' => ['webhook'],
            'notify_emails' => null,
            'webhook_url' => 'https://example.com/hook',
        ]);

        app(AlertEvaluationService::class)->evaluate($rule);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/hook'
            && $request['domain'] === $domain->fqdn);

        $this->assertEquals(['webhook'], AlertEvent::first()->notified_channels);
    }

    public function test_failed_webhook_delivery_is_not_recorded_as_notified(): void
    {
        Http::fake(['https://example.com/hook' => Http::response('', 500)]);

        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subHours(2),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'count' => 10,
            'dkim_result' => 'fail',
            'spf_result' => 'fail',
        ]);

        $rule = AlertRule::factory()->create([
            'domain_id' => $domain->id,
            'type' => 'pass_rate_drop',
            'threshold_percent' => 95,
            'channels' => ['webhook'],
            'notify_emails' => null,
            'webhook_url' => 'https://example.com/hook',
        ]);

        app(AlertEvaluationService::class)->evaluate($rule);

        $this->assertEquals([], AlertEvent::first()->notified_channels);
    }
}
