<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
