<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ReportRecordsViewTest extends TestCase
{
    use RefreshDatabase;

    private function recordsView(User $user, array $query = []): Testable
    {
        return Livewire::withQueryParams(['view' => 'records'] + $query)->actingAs($user)->test('reports.index');
    }

    /**
     * @return list<string>
     */
    private function listedIps(Testable $component): array
    {
        return $component->viewData('records')->pluck('source_ip')->all();
    }

    private function report(array $attributes = []): AggregateReport
    {
        return AggregateReport::factory()->create(array_merge(['domain_id' => Domain::factory()->create()->id], $attributes));
    }

    public function test_the_records_tab_lists_the_records_of_reports_the_user_can_see(): void
    {
        $organisation = Organisation::factory()->create();
        $visible = $this->report(['domain_id' => Domain::factory()->create(['organisation_id' => $organisation->id])->id]);
        $hidden = $this->report(['domain_id' => Domain::factory()->create(['organisation_id' => Organisation::factory()->create()->id])->id]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $visible->id, 'source_ip' => '203.0.113.1']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $hidden->id, 'source_ip' => '203.0.113.2']);

        $component = $this->recordsView(User::factory()->scopedTo($organisation)->create());

        $this->assertSame(['203.0.113.1'], $this->listedIps($component));
        $this->assertNull($component->viewData('reports'));
        $component->assertSee('View report');
    }

    public function test_result_filters_keep_only_matching_records_not_whole_reports(): void
    {
        $report = $this->report();
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '203.0.113.1', 'spf_result' => 'fail', 'disposition' => 'quarantine']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '203.0.113.2']);

        $user = User::factory()->create();

        $this->assertSame(['203.0.113.1'], $this->listedIps($this->recordsView($user, ['spf_result' => 'fail'])));
        $this->assertSame(['203.0.113.1'], $this->listedIps($this->recordsView($user, ['disposition' => 'quarantine'])));
        $this->assertSame([], $this->listedIps($this->recordsView($user, ['dkim_result' => 'fail'])));
    }

    public function test_search_looks_through_sender_details_asn_and_reporting_org(): void
    {
        $google = $this->report(['org_name' => 'google.com']);
        $microsoft = $this->report(['org_name' => 'Microsoft Corporation']);
        AggregateReportRecord::factory()->enriched('Cloudflare, Inc.', 'mail.example.net')->create(['aggregate_report_id' => $google->id, 'source_ip' => '203.0.113.1', 'asn' => 13335]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $microsoft->id, 'source_ip' => '203.0.113.2']);

        $user = User::factory()->create();

        $this->assertSame(['203.0.113.1'], $this->listedIps($this->recordsView($user, ['search' => 'mail.example'])));
        $this->assertSame(['203.0.113.1'], $this->listedIps($this->recordsView($user, ['search' => 'cloudflare'])));
        $this->assertSame(['203.0.113.1'], $this->listedIps($this->recordsView($user, ['search' => 'AS13335'])));
        $this->assertSame(['203.0.113.2'], $this->listedIps($this->recordsView($user, ['search' => 'Microsoft'])));
    }

    public function test_domain_and_date_filters_apply_to_the_records_report(): void
    {
        $recent = $this->report(['date_range_begin' => now()->subDays(2), 'date_range_end' => now()->subDay()]);
        $old = $this->report(['date_range_begin' => now()->subDays(40), 'date_range_end' => now()->subDays(39)]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $recent->id, 'source_ip' => '203.0.113.1']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $old->id, 'source_ip' => '203.0.113.2']);

        $user = User::factory()->create();

        $this->assertSame(['203.0.113.1'], $this->listedIps($this->recordsView($user, ['from' => now()->subDays(7)->toDateString()])));
        $this->assertSame(['203.0.113.2'], $this->listedIps($this->recordsView($user, ['domain_id' => $old->domain_id])));
    }

    public function test_records_sort_by_count_and_fall_back_to_newest_first(): void
    {
        $recent = $this->report(['date_range_begin' => now()->subDays(2)]);
        $old = $this->report(['date_range_begin' => now()->subDays(20)]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $old->id, 'source_ip' => '203.0.113.1', 'count' => 90]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $recent->id, 'source_ip' => '203.0.113.2', 'count' => 5]);

        $user = User::factory()->create();

        $this->assertSame(['203.0.113.2', '203.0.113.1'], $this->listedIps($this->recordsView($user)));
        $this->assertSame(['203.0.113.1', '203.0.113.2'], $this->listedIps($this->recordsView($user, ['sort' => 'count', 'dir' => 'desc'])));
        $this->assertSame(['203.0.113.2', '203.0.113.1'], $this->listedIps($this->recordsView($user, ['sort' => 'messages', 'dir' => 'desc'])));
    }

    public function test_switching_tabs_keeps_the_filters_and_clears_the_sort(): void
    {
        Livewire::withQueryParams(['spf_result' => 'fail', 'sort' => 'messages', 'dir' => 'asc'])->actingAs(User::factory()->create())->test('reports.index')
            ->call('selectTab', 'records')
            ->assertSet('view', 'records')
            ->assertSet('spf_result', 'fail')
            ->assertSet('sortBy', '')
            ->call('selectTab', 'bogus')
            ->assertSet('view', 'reports');

        Livewire::withQueryParams(['view' => 'bogus'])->actingAs(User::factory()->create())->test('reports.index')->assertSet('view', 'reports');
    }

    public function test_the_records_export_holds_only_the_matching_records(): void
    {
        $report = $this->report();
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '203.0.113.1', 'spf_result' => 'fail']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '203.0.113.2']);

        $user = User::factory()->create();

        $this->assertSame(['203.0.113.1'], $this->exportedIps($this->actingAs($user)->get(route('reports.export', ['view' => 'records', 'spf_result' => 'fail']))));
        $this->assertEqualsCanonicalizing(['203.0.113.1', '203.0.113.2'], $this->exportedIps($this->actingAs($user)->get(route('reports.export', ['spf_result' => 'fail']))));
    }

    /**
     * @return list<string>
     */
    private function exportedIps(TestResponse $response): array
    {
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

        return array_column(array_slice($rows, 1), 5);
    }
}
