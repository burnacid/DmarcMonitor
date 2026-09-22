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

class ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    private function csvRows(TestResponse $response): array
    {
        $content = $response->streamedContent();

        return array_map('str_getcsv', array_filter(explode("\n", trim($content))));
    }

    public function test_export_streams_one_csv_row_per_record(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'org_name' => 'google.com']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '203.0.113.9']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '203.0.113.10']);

        $response = $this->actingAs($user)->get(route('reports.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $rows = $this->csvRows($response);
        $this->assertCount(3, $rows); // header + 2 records
        $this->assertEquals('Domain', $rows[0][0]);
        $this->assertEqualsCanonicalizing(
            ['203.0.113.9', '203.0.113.10'],
            [$rows[1][5], $rows[2][5]]
        );
    }

    public function test_export_applies_the_same_filters_as_the_reports_list(): void
    {
        $user = User::factory()->create();
        $domainA = Domain::factory()->create(['fqdn' => 'a.example.com']);
        $domainB = Domain::factory()->create(['fqdn' => 'b.example.com']);
        $reportA = AggregateReport::factory()->create(['domain_id' => $domainA->id]);
        $reportB = AggregateReport::factory()->create(['domain_id' => $domainB->id]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $reportA->id, 'source_ip' => '203.0.113.9']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $reportB->id, 'source_ip' => '203.0.113.10']);

        $response = $this->actingAs($user)->get(route('reports.export', ['domain_id' => $domainA->id]));

        $rows = $this->csvRows($response);
        $this->assertCount(2, $rows); // header + 1 record
        $this->assertEquals('a.example.com', $rows[1][0]);
    }

    public function test_a_scoped_user_cannot_export_another_organisations_data_via_query_params(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();
        $domainA = Domain::factory()->create(['organisation_id' => $orgA->id]);
        $domainB = Domain::factory()->create(['organisation_id' => $orgB->id, 'fqdn' => 'b.example.com']);
        $reportA = AggregateReport::factory()->create(['domain_id' => $domainA->id]);
        $reportB = AggregateReport::factory()->create(['domain_id' => $domainB->id]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $reportA->id]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $reportB->id]);

        $user = User::factory()->create();
        $user->organisations()->attach($orgA->id);

        // Crafting a domain_id filter for org B's domain should still yield nothing.
        $response = $this->actingAs($user)->get(route('reports.export', ['domain_id' => $domainB->id]));

        $rows = $this->csvRows($response);
        $this->assertCount(1, $rows); // header only

        // No filter at all should still only include org A's data.
        $response = $this->actingAs($user)->get(route('reports.export'));
        $rows = $this->csvRows($response);
        $this->assertCount(2, $rows); // header + org A's 1 record
        $this->assertNotEquals('b.example.com', $rows[1][0]);
    }
}
