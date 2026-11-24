<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\ExpectedSource;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Analytics\DomainHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class NullSenderDisplayTest extends TestCase
{
    use RefreshDatabase;

    private AggregateReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        Livewire::withoutLazyLoading();

        $domain = Domain::factory()->create(['organisation_id' => Organisation::factory(), 'fqdn' => 'example.com']);
        $this->report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'date_range_begin' => now()]);
    }

    private function inkyRecord(?ExpectedSource $expectedSource = null): AggregateReportRecord
    {
        return AggregateReportRecord::factory()->enriched('Amazon.com, Inc.')->create([
            'aggregate_report_id' => $this->report->id,
            'source_ip' => '34.249.87.65',
            'envelope_from' => '<>',
            'spf_domain' => 'ipw.inkyphishfence.com',
            'spf_scope' => 'helo',
            'spf_result' => 'fail',
            'dkim_result' => 'fail',
            'is_null_sender' => true,
            'expected_source_id' => $expectedSource?->id,
        ]);
    }

    public function test_a_report_labels_an_unmatched_null_sender_record_and_offers_to_mark_it_as_expected(): void
    {
        $this->inkyRecord();

        Volt::actingAs(User::factory()->editor()->create())->test('reports.show', ['report' => $this->report])
            ->assertSee('Null sender')
            ->assertSee('Mark as expected')
            ->assertSee('match_value=ipw.inkyphishfence.com', false);
    }

    public function test_viewers_are_not_offered_to_mark_a_record_as_expected(): void
    {
        $this->inkyRecord();

        Volt::actingAs(User::factory()->viewer()->create())->test('reports.show', ['report' => $this->report])
            ->assertSee('Null sender')
            ->assertDontSee('Mark as expected');
    }

    public function test_a_matched_record_shows_its_expected_source(): void
    {
        $this->inkyRecord(ExpectedSource::factory()->create(['label' => 'INKY']));

        Volt::actingAs(User::factory()->create())->test('reports.show', ['report' => $this->report])
            ->assertSee('Expected: INKY')
            ->assertDontSee('Mark as expected');
    }

    public function test_the_dashboard_shows_only_the_expected_source_label_of_a_source(): void
    {
        $this->inkyRecord(ExpectedSource::factory()->create(['label' => 'INKY']));

        Volt::actingAs(User::factory()->create())->test('dashboard')
            ->assertViewHas('sourceGroups', fn (Collection $groups) => $groups->sole()['expected_labels'] === ['INKY'])
            ->assertSee('Expected: INKY')
            ->assertDontSee('via ipw.inkyphishfence.com')
            ->assertDontSee('Null sender');
    }

    public function test_expected_sources_hidden_from_the_dashboard_are_left_out_of_its_figures(): void
    {
        $this->inkyRecord(ExpectedSource::factory()->create(['label' => 'INKY', 'hide_from_dashboard' => true]));
        $healthy = AggregateReportRecord::factory()->enriched('Google LLC')->create(['aggregate_report_id' => $this->report->id, 'count' => 20]);

        Volt::actingAs(User::factory()->create())->test('dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['total'] === 20 && $summary['dmarc_pass_pct'] === 100.0)
            ->assertViewHas('failureBreakdown', fn (array $breakdown) => $breakdown['both_fail'] === 0)
            ->assertViewHas('sourceGroups', fn (Collection $groups) => $groups->pluck('label')->all() === ['Google LLC'])
            ->assertViewHas('hiddenExpectedMessages', AggregateReportRecord::whereKeyNot($healthy->id)->sole()->count)
            ->assertSee('from expected sources are hidden');
    }

    public function test_expected_sources_not_hidden_still_count_on_the_dashboard(): void
    {
        $this->inkyRecord(ExpectedSource::factory()->create(['hide_from_dashboard' => false]));

        Volt::actingAs(User::factory()->create())->test('dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['dmarc_pass_pct'] === 0.0)
            ->assertViewHas('hiddenExpectedMessages', 0)
            ->assertDontSee('from expected sources are hidden');
    }

    public function test_hidden_expected_sources_do_not_lower_a_domains_dmarc_score(): void
    {
        $this->inkyRecord(ExpectedSource::factory()->create(['hide_from_dashboard' => true]));
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $this->report->id, 'count' => 20]);

        $health = app(DomainHealthService::class)->forDomains(Domain::whereKey($this->report->domain_id)->get());

        $this->assertSame(100.0, $health->first()['dmarc_pass_pct']);
    }

    public function test_the_sources_export_leaves_out_hidden_expected_sources(): void
    {
        $this->inkyRecord(ExpectedSource::factory()->create(['hide_from_dashboard' => true]));

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard.export-sources'));

        $this->assertCount(1, array_filter(explode("\n", trim($response->streamedContent()))));
    }

    public function test_the_sources_export_includes_the_null_sender_columns(): void
    {
        $this->inkyRecord(ExpectedSource::factory()->create(['label' => 'INKY']));

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard.export-sources'));

        $rows = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('ipw.inkyphishfence.com', $row['HELO Domain']);
        $this->assertSame('INKY', $row['Expected Source']);
        $this->assertSame((string) AggregateReportRecord::sole()->count, $row['Null Sender']);
    }
}
