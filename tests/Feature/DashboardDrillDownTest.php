<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardDrillDownTest extends TestCase
{
    use RefreshDatabase;

    public function test_clicking_a_day_scopes_the_summary_to_that_day_only(): void
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
            'dkim_result' => 'fail',
            'spf_result' => 'fail',
        ]);

        $today = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now(),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $today->id,
            'count' => 20,
            'dkim_result' => 'pass',
            'spf_result' => 'pass',
        ]);

        $component = Volt::actingAs($user)->test('dashboard');

        // Before drilling in, the summary spans the whole rolling window.
        $this->assertEquals(30, $component->viewData('summary')['total']);

        $component->call('selectDay', now()->toDateString());

        $this->assertEquals(20, $component->viewData('summary')['total']);

        // Clicking the same day again clears the drill-down.
        $component->call('selectDay', now()->toDateString());

        $this->assertEquals(30, $component->viewData('summary')['total']);
    }

    public function test_changing_the_days_filter_clears_the_selected_day(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)->test('dashboard')
            ->call('selectDay', now()->toDateString());

        $this->assertEquals(now()->toDateString(), $component->get('selectedDay'));

        $component->set('days', 7);

        $this->assertNull($component->get('selectedDay'));
    }

    public function test_the_reports_link_behind_an_envelope_row_also_filters_by_envelope_to(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);

        // Same source IP, two distinct envelope_to domains -> one IP group,
        // expanded into two envelope rows each with their own "Reports" link.
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.9',
            'envelope_to' => 'recipient.example.net',
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.9',
            'envelope_to' => 'other.example.org',
        ]);

        Volt::actingAs($user)->test('dashboard')
            ->assertSee('envelope=recipient.example.net')
            ->assertSee('envelope=other.example.org');
    }

    public function test_the_reports_link_omits_the_envelope_filter_when_no_envelope_to_was_recorded(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);

        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.9',
            'envelope_to' => 'recipient.example.net',
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.9',
            'envelope_to' => null,
        ]);

        Volt::actingAs($user)->test('dashboard')
            ->assertSee('envelope=recipient.example.net')
            ->assertDontSee('envelope=%28no+envelope-to+data%29');
    }
}
