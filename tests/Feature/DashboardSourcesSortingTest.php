<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardSourcesSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The dashboard is lazy-loaded; render its real content, not the placeholder.
        Livewire::withoutLazyLoading();
    }

    public function test_sending_sources_are_ordered_by_volume_until_a_header_is_clicked(): void
    {
        $this->createSources();

        $this->assertSourceOrder(['Bravo', 'Charlie', 'Alpha'], $this->dashboard());
    }

    public function test_clicking_a_header_sorts_by_that_column_and_clicking_again_reverses_it(): void
    {
        $this->createSources();

        $dashboard = $this->dashboard()->call('sort', 'source');
        $this->assertSourceOrder(['Alpha', 'Bravo', 'Charlie'], $dashboard);

        $dashboard->call('sort', 'source');
        $this->assertSourceOrder(['Charlie', 'Bravo', 'Alpha'], $dashboard);
    }

    public function test_sorting_by_dmarc_pass_rate_lists_the_worst_sources_first_and_larger_volume_first_on_a_tie(): void
    {
        $this->createSources();

        $this->assertSourceOrder(['Charlie', 'Bravo', 'Alpha'], $this->dashboard()->call('sort', 'dmarc'));
    }

    public function test_an_unknown_sort_column_from_the_address_falls_back_to_volume(): void
    {
        $this->createSources();

        $dashboard = Volt::actingAs(User::factory()->create())
            ->withQueryParams(['sort' => 'envelope_to', 'dir' => 'asc'])
            ->test('dashboard');

        $this->assertSourceOrder(['Bravo', 'Charlie', 'Alpha'], $dashboard);
    }

    public function test_the_sending_sources_table_shows_an_overlay_while_sorting(): void
    {
        $this->createSources();

        $this->dashboard()->assertSeeHtmlInOrder(['wire:target="sort"', __('Sorting…'), __('Sending sources')]);
    }

    private function dashboard(): Testable
    {
        return Volt::actingAs(User::factory()->create())->test('dashboard');
    }

    /**
     * Alpha: 10 messages, all passing. Bravo: 30 messages, all passing.
     * Charlie: 20 messages, all failing.
     */
    private function createSources(): void
    {
        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'date_range_begin' => now()]);

        foreach (['Alpha' => [10, 'pass'], 'Bravo' => [30, 'pass'], 'Charlie' => [20, 'fail']] as $name => [$count, $result]) {
            AggregateReportRecord::factory()->enriched($name)->create([
                'aggregate_report_id' => $report->id,
                'count' => $count,
                'dkim_result' => $result,
                'spf_result' => $result,
            ]);
        }
    }

    /**
     * @param  array<int, string>  $expected
     */
    private function assertSourceOrder(array $expected, Testable $dashboard): void
    {
        $dashboard->assertViewHas('sourceGroups', fn (Collection $groups) => $groups->pluck('label')->all() === $expected);
    }
}
