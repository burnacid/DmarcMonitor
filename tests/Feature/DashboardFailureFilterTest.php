<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardFailureFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The dashboard is lazy-loaded; render its real content, not the placeholder.
        Livewire::withoutLazyLoading();
    }

    public function test_clicking_a_slice_narrows_the_tiles_and_source_tables_to_that_category(): void
    {
        $this->createSources();

        $this->dashboard()
            ->call('selectFailureCategory', 'spf_fail_only')
            ->assertSet('failureCategory', 'spf_fail_only')
            ->assertViewHas('summary', fn (array $summary) => $summary['total'] === 20)
            ->assertViewHas('sourceGroups', fn (Collection $groups) => $groups->pluck('label')->all() === ['SpfBroken'])
            ->assertViewHas('topFailingSources', fn (Collection $groups) => $groups->isEmpty());
    }

    public function test_the_chart_keeps_every_slice_and_highlights_the_picked_one(): void
    {
        $this->createSources();

        $this->dashboard()
            ->call('selectFailureCategory', 'both_fail')
            ->assertDispatched('failure-breakdown-updated', fn (string $event, array $params) => $params['selected'] === 'both_fail' && $params['breakdown']['total'] === 60)
            ->assertViewHas('failureBreakdown', fn (array $breakdown) => $breakdown['total'] === 60)
            ->assertViewHas('sourceGroups', fn (Collection $groups) => $groups->pluck('label')->all() === ['Spoofer']);
    }

    public function test_clicking_the_same_slice_again_clears_the_filter(): void
    {
        $this->createSources();

        $this->dashboard()
            ->call('selectFailureCategory', 'spf_fail_only')
            ->call('selectFailureCategory', 'spf_fail_only')
            ->assertSet('failureCategory', null)
            ->assertViewHas('summary', fn (array $summary) => $summary['total'] === 60);
    }

    public function test_an_unknown_category_from_the_address_is_ignored(): void
    {
        $this->createSources();

        Volt::actingAs(User::factory()->create())
            ->withQueryParams(['failure' => 'bogus'])
            ->test('dashboard')
            ->assertSet('failureCategory', null)
            ->assertViewHas('summary', fn (array $summary) => $summary['total'] === 60);
    }

    private function dashboard(): Testable
    {
        return Volt::actingAs(User::factory()->create())->test('dashboard');
    }

    /**
     * Healthy: 30 messages, both pass. SpfBroken: 20, SPF fails only.
     * Spoofer: 10, both fail.
     */
    private function createSources(): void
    {
        $report = AggregateReport::factory()->create(['date_range_begin' => now()]);

        foreach (['Healthy' => [30, 'pass', 'pass'], 'SpfBroken' => [20, 'fail', 'pass'], 'Spoofer' => [10, 'fail', 'fail']] as $name => [$count, $spf, $dkim]) {
            AggregateReportRecord::factory()->enriched($name)->create([
                'aggregate_report_id' => $report->id,
                'count' => $count,
                'spf_result' => $spf,
                'dkim_result' => $dkim,
            ]);
        }
    }
}
