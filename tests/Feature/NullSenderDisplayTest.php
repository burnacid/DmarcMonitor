<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\ExpectedSource;
use App\Models\Organisation;
use App\Models\User;
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
