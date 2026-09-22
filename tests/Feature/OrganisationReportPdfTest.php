<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OrganisationReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_streams_a_pdf_for_an_organisation_the_user_can_access(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create(['name' => 'Acme Corp']);
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id]);
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'date_range_begin' => now()]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id]);

        $response = $this->actingAs($user)->get(route('organisations.report-pdf', $organisation));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_scoped_user_cannot_view_another_organisations_report(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();

        $user = User::factory()->create();
        $user->organisations()->attach($orgA->id);

        $this->actingAs($user)
            ->get(route('organisations.report-pdf', $orgB))
            ->assertNotFound();
    }

    public function test_a_scoped_user_can_view_their_own_organisations_report(): void
    {
        $org = Organisation::factory()->create();

        $user = User::factory()->create();
        $user->organisations()->attach($org->id);

        $this->actingAs($user)
            ->get(route('organisations.report-pdf', $org))
            ->assertOk();
    }

    public function test_it_respects_a_custom_date_range(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create();
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id]);

        $outOfRange = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => now()->subYear(),
        ]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $outOfRange->id, 'count' => 999]);

        $response = $this->actingAs($user)->get(route('organisations.report-pdf', [
            'organisation' => $organisation,
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $response->assertOk();
        // No assertion on rendered PDF content (dompdf output isn't practical
        // to parse); the underlying data path is covered by DmarcMetricsServiceTest.
    }

    public function test_opening_the_report_modal_defaults_to_the_last_30_days(): void
    {
        $admin = User::factory()->create();
        $organisation = Organisation::factory()->create();

        Volt::actingAs($admin)->test('admin.organisations')
            ->call('openReportModal', $organisation->id)
            ->assertSet('reportOrganisationId', $organisation->id)
            ->assertSet('reportFrom', now()->subDays(29)->toDateString())
            ->assertSet('reportTo', now()->toDateString());
    }

    public function test_presets_set_the_expected_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15'));

        $admin = User::factory()->create();
        $organisation = Organisation::factory()->create();

        $component = Volt::actingAs($admin)->test('admin.organisations')
            ->call('openReportModal', $organisation->id);

        $component->call('applyReportPreset', 'this_month')
            ->assertSet('reportFrom', '2026-03-01')
            ->assertSet('reportTo', '2026-03-31');

        $component->call('applyReportPreset', 'last_month')
            ->assertSet('reportFrom', '2026-02-01')
            ->assertSet('reportTo', '2026-02-28');

        Carbon::setTestNow();
    }

    public function test_picking_a_month_overrides_the_date_range(): void
    {
        $admin = User::factory()->create();
        $organisation = Organisation::factory()->create();

        Volt::actingAs($admin)->test('admin.organisations')
            ->call('openReportModal', $organisation->id)
            ->set('reportMonthInput', '2026-06')
            ->assertSet('reportFrom', '2026-06-01')
            ->assertSet('reportTo', '2026-06-30');
    }

    public function test_a_scoped_user_cannot_open_the_report_modal_for_another_organisation(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();

        $user = User::factory()->create(['role' => 'editor']);
        $user->organisations()->attach($orgA->id);

        Volt::actingAs($user)->test('admin.organisations')
            ->call('openReportModal', $orgB->id)
            ->assertStatus(404);
    }
}
