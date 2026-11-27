<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public function test_the_sending_sources_table_shows_an_overlay_while_sorting_or_changing_pages(): void
    {
        $this->createSources();

        $dashboard = $this->dashboard()->assertSeeHtmlInOrder([
            'wire:target="sort, gotoPage, nextPage, previousPage"',
            __('Sorting…'),
            'wire:target="gotoPage, nextPage, previousPage"',
            __('Loading…'),
            __('Sending sources'),
        ]);

        // Livewire only hides loading elements up front for its known attribute names; others (e.g. wire:loading.delay.flex) show all the time.
        $this->assertMatchesRegularExpression('/\swire:loading\.delay\s+wire:target="sort, gotoPage, nextPage, previousPage"/', $dashboard->html());
    }

    public function test_long_source_names_are_truncated_with_the_full_name_on_hover(): void
    {
        $longName = "Compagnie Francaise d'Assurance pour le Commerce Exterieur S.A.";
        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'date_range_begin' => now()]);
        AggregateReportRecord::factory()->enriched($longName)->create(['aggregate_report_id' => $report->id]);

        $this->dashboard()->assertSeeHtml('class="block max-w-xs truncate" title="'.e($longName).'"');
    }

    public function test_sources_beyond_the_first_25_are_on_the_next_page_with_their_envelope_split(): void
    {
        $this->createManySources(26);

        $dashboard = $this->dashboard();
        $dashboard->assertViewHas('sourceGroups', fn (LengthAwarePaginator $groups) => $groups->count() === 25 && $groups->total() === 26);

        $dashboard->call('gotoPage', 2)->assertViewHas('sourceGroups', fn (LengthAwarePaginator $groups) => $groups->pluck('label')->all() === ['Sender 26'] && isset($groups->first()['envelopes']));
    }

    public function test_sorting_covers_every_source_not_just_the_first_page(): void
    {
        $this->createManySources(26);

        // The smallest sender is the only one failing, so it leads the first page when sorted on DMARC pass.
        AggregateReportRecord::query()->orderBy('count')->first()->update(['dkim_result' => 'fail', 'spf_result' => 'fail']);

        $this->dashboard()->call('gotoPage', 2)->call('sort', 'dmarc')
            ->assertViewHas('sourceGroups', fn (LengthAwarePaginator $groups) => $groups->currentPage() === 1 && $groups->first()['label'] === 'Sender 26');
    }

    public function test_a_filter_change_goes_back_to_the_first_page(): void
    {
        $this->createManySources(26);

        $this->dashboard()->call('gotoPage', 2)->call('clearSelectedDay')
            ->assertViewHas('sourceGroups', fn (LengthAwarePaginator $groups) => $groups->currentPage() === 1);
    }

    /**
     * Sender 1 sends the most mail and Sender $count the least, all passing.
     */
    private function createManySources(int $count): void
    {
        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'date_range_begin' => now()]);

        foreach (range(1, $count) as $number) {
            AggregateReportRecord::factory()->enriched("Sender {$number}")->create([
                'aggregate_report_id' => $report->id,
                'count' => 100 - $number,
                'dkim_result' => 'pass',
                'spf_result' => 'pass',
            ]);
        }
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
        $dashboard->assertViewHas('sourceGroups', fn (LengthAwarePaginator $groups) => $groups->pluck('label')->all() === $expected);
    }
}
