<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DashboardSourcesExportTest extends TestCase
{
    use RefreshDatabase;

    private function csvRows(TestResponse $response): array
    {
        $content = $response->streamedContent();

        return array_map('str_getcsv', array_filter(explode("\n", trim($content))));
    }

    public function test_export_streams_one_row_per_grouped_sending_source(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'date_range_begin' => now()]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '203.0.113.9']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '203.0.113.10']);

        $response = $this->actingAs($user)->get(route('dashboard.export-sources'));

        $response->assertOk();
        $rows = $this->csvRows($response);
        $this->assertCount(3, $rows); // header + 2 distinct source groups
        $this->assertEquals('Domain', $rows[0][0]);
    }

    public function test_a_scoped_user_cannot_export_another_organisations_domain_by_id(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();
        Domain::factory()->create(['organisation_id' => $orgA->id]);
        $domainB = Domain::factory()->create(['organisation_id' => $orgB->id]);

        $user = User::factory()->create();
        $user->organisations()->attach($orgA->id);

        $this->actingAs($user)
            ->get(route('dashboard.export-sources', ['domain_id' => $domainB->id]))
            ->assertNotFound();
    }

    public function test_a_scoped_user_cannot_export_another_organisation_by_id(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();

        $user = User::factory()->create();
        $user->organisations()->attach($orgA->id);

        $this->actingAs($user)
            ->get(route('dashboard.export-sources', ['organisation_id' => $orgB->id]))
            ->assertNotFound();
    }
}
