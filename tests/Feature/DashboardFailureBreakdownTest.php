<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardFailureBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_failure_breakdown_splits_the_window_by_which_mechanism_failed(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now(),
        ]);

        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'count' => 10,
            'spf_result' => 'pass',
            'dkim_result' => 'pass',
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'count' => 3,
            'spf_result' => 'fail',
            'dkim_result' => 'fail',
        ]);

        $breakdown = Volt::actingAs($user)->test('dashboard')->viewData('failureBreakdown');

        $this->assertEquals(13, $breakdown['total']);
        $this->assertEquals(10, $breakdown['both_pass']);
        $this->assertEquals(3, $breakdown['both_fail']);
        $this->assertEquals(0, $breakdown['spf_fail_only']);
        $this->assertEquals(0, $breakdown['dkim_fail_only']);
    }

    public function test_failure_breakdown_respects_the_selected_day_drill_down(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();

        $yesterday = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subDay(),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $yesterday->id,
            'count' => 10,
            'spf_result' => 'fail',
            'dkim_result' => 'fail',
        ]);

        $today = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now(),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $today->id,
            'count' => 5,
            'spf_result' => 'pass',
            'dkim_result' => 'pass',
        ]);

        $component = Volt::actingAs($user)->test('dashboard');

        $this->assertEquals(15, $component->viewData('failureBreakdown')['total']);

        $component->call('selectDay', now()->toDateString());

        $this->assertEquals(5, $component->viewData('failureBreakdown')['total']);
        $this->assertEquals(5, $component->viewData('failureBreakdown')['both_pass']);
    }

    public function test_top_failing_sources_excludes_low_volume_and_fully_passing_sources(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);

        // Below the minimum-volume threshold — should not appear even though it's 0% pass.
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.1',
            'count' => 1,
            'spf_result' => 'fail',
            'dkim_result' => 'fail',
        ]);

        // Fully passing — should not appear in a "failing sources" list.
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.2',
            'count' => 20,
            'spf_result' => 'pass',
            'dkim_result' => 'pass',
        ]);

        // High volume and failing — should appear.
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.3',
            'count' => 20,
            'spf_result' => 'fail',
            'dkim_result' => 'fail',
        ]);

        $topFailing = Volt::actingAs($user)->test('dashboard')->viewData('topFailingSources');

        $this->assertCount(1, $topFailing);
        $this->assertEquals('203.0.113.3', $topFailing->first()['label']);
    }
}
