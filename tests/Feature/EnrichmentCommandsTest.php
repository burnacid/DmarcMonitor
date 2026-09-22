<?php

namespace Tests\Feature;

use App\Jobs\EnrichReportRecordsJob;
use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\IpEnrichmentCache;
use App\Services\Enrichment\IpEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnrichmentCommandsTest extends TestCase
{
    use RefreshDatabase;

    // --- dmarc:backfill-enrichment ---

    public function test_backfill_command_skips_already_enriched_records(): void
    {
        $report = AggregateReport::factory()->create();
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'enriched_at' => now(),
        ]);

        $this->artisan('dmarc:backfill-enrichment')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);
    }

    public function test_backfill_command_enriches_records_missing_enrichment(): void
    {
        $this->app->bind(IpEnrichmentService::class, fn () => new IpEnrichmentService(
            fn (string $ip) => 'mail.example.com',
        ));

        $report = AggregateReport::factory()->create();
        $record = AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.1',
            'enriched_at' => null,
        ]);

        $this->artisan('dmarc:backfill-enrichment')
            ->expectsOutputToContain('Backfilled enrichment for 1 record(s)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('aggregate_report_records', [
            'id' => $record->id,
            'ptr_hostname' => 'mail.example.com',
        ]);
        $this->assertNotNull($record->fresh()->enriched_at);
    }

    public function test_backfill_command_uses_cache_to_avoid_redundant_dns_lookups(): void
    {
        $calls = 0;
        $this->app->bind(IpEnrichmentService::class, fn () => new IpEnrichmentService(
            function (string $ip) use (&$calls) {
                $calls++;

                return 'mail.example.com';
            }
        ));

        // Pre-seed the cache for one of the two IPs.
        IpEnrichmentCache::create([
            'ip' => '203.0.113.2',
            'ptr_hostname' => 'cached.example.com',
            'looked_up_at' => now(),
            'lookup_failed' => false,
        ]);

        $report = AggregateReport::factory()->create();
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.2',
            'enriched_at' => null,
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.3',
            'enriched_at' => null,
        ]);

        $this->artisan('dmarc:backfill-enrichment')->assertExitCode(0);

        // Only the un-cached IP triggers a DNS call.
        $this->assertEquals(1, $calls);
    }

    public function test_backfill_force_option_re_enriches_already_enriched_records(): void
    {
        $this->app->bind(IpEnrichmentService::class, fn () => new IpEnrichmentService(
            fn (string $ip) => 'fresh.example.com',
        ));

        $report = AggregateReport::factory()->create();
        $record = AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.4',
            'ptr_hostname' => 'old.example.com',
            'enriched_at' => now()->subDays(10),
        ]);

        $this->artisan('dmarc:backfill-enrichment --force')
            ->expectsOutputToContain('Backfilled enrichment for 1 record(s)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('aggregate_report_records', [
            'id' => $record->id,
            'ptr_hostname' => 'fresh.example.com',
        ]);
    }

    // --- dmarc:enrich-pending ---

    public function test_enrich_pending_reports_all_already_enriched(): void
    {
        Queue::fake();

        $report = AggregateReport::factory()->create();
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'enriched_at' => now(),
        ]);

        $this->artisan('dmarc:enrich-pending')
            ->expectsOutputToContain('All records are already enriched')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_enrich_pending_dispatches_jobs_for_reports_with_un_enriched_records(): void
    {
        Queue::fake();

        $report = AggregateReport::factory()->create();
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'enriched_at' => null,
        ]);

        $this->artisan('dmarc:enrich-pending')
            ->expectsOutputToContain('Dispatched 1 enrichment job(s)')
            ->assertExitCode(0);

        Queue::assertPushed(EnrichReportRecordsJob::class, 1);
        Queue::assertPushed(EnrichReportRecordsJob::class, fn ($job) => $job->aggregateReportId === $report->id);
    }
}
