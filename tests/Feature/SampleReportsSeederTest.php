<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\Domain;
use App\Support\AggregateReportXml;
use Database\Seeders\SampleReportsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SampleReportsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_reports_with_their_raw_xml_for_active_domains_only(): void
    {
        Storage::fake('local');
        Queue::fake();
        $active = Domain::factory()->create(['fqdn' => 'example.com', 'is_active' => true]);
        $inactive = Domain::factory()->create(['fqdn' => 'example.org', 'is_active' => false]);

        $this->seed(SampleReportsSeeder::class);

        $this->assertGreaterThan(0, $active->aggregateReports()->count());
        $this->assertSame(0, $inactive->aggregateReports()->count());

        $report = $active->aggregateReports()->with('records')->first();
        $this->assertCount($report->records->count(), AggregateReportXml::records(AggregateReportXml::read($report)));
    }

    public function test_running_it_again_replaces_the_reports_instead_of_adding_more(): void
    {
        Storage::fake('local');
        Queue::fake();
        Domain::factory()->create(['is_active' => true]);

        $this->seed(SampleReportsSeeder::class);
        $this->seed(SampleReportsSeeder::class);

        $this->assertLessThanOrEqual(SampleReportsSeeder::DAYS * 3, AggregateReport::count());
    }
}
