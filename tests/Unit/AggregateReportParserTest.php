<?php

namespace Tests\Unit;

use App\Jobs\EnrichReportRecordsJob;
use App\Models\Domain;
use App\Services\Dmarc\AggregateReportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AggregateReportParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Parsing dispatches an IP-enrichment job that would otherwise hit real
        // DNS/GeoIP lookups under the sync queue driver used in tests.
        Queue::fake();
    }

    private function fixture(string $name): string
    {
        return base_path("tests/Fixtures/dmarc/aggregate/{$name}");
    }

    public function test_it_parses_a_single_record_report_and_auto_creates_the_domain(): void
    {
        $report = (new AggregateReportParser)->parseFile($this->fixture('google-single-record.xml'));

        $this->assertDatabaseHas('domains', [
            'fqdn' => 'example.com',
            'is_active' => false,
        ]);

        $this->assertEquals('google.com', $report->org_name);
        $this->assertEquals('12345678901234567890', $report->report_id);
        $this->assertEquals('reject', $report->policy_p);
        $this->assertEquals('reject', $report->policy_sp);
        $this->assertEquals(100, $report->policy_pct);
        $this->assertEquals('r', $report->policy_adkim);
        $this->assertEquals('r', $report->policy_aspf);
        $this->assertEquals('2025-01-01 00:00:00', $report->date_range_begin->format('Y-m-d H:i:s'));

        $this->assertCount(1, $report->records);
        $record = $report->records->first();
        $this->assertEquals('209.85.220.41', $record->source_ip);
        $this->assertEquals(2, $record->count);
        $this->assertEquals('none', $record->disposition);
        $this->assertEquals('pass', $record->dkim_result);
        $this->assertEquals('pass', $record->spf_result);
        $this->assertEquals('example.com', $record->dkim_domain);
        $this->assertEquals('google', $record->dkim_selector);
        $this->assertEquals('example.com', $record->spf_domain);
    }

    public function test_it_resolves_an_existing_active_domain_instead_of_creating_a_new_one(): void
    {
        $domain = Domain::create(['fqdn' => 'example.com', 'is_active' => true]);

        $report = (new AggregateReportParser)->parseFile($this->fixture('google-single-record.xml'));

        $this->assertEquals($domain->id, $report->domain_id);
        $this->assertDatabaseCount('domains', 1);
    }

    public function test_it_parses_multiple_records_with_multiple_dkim_auth_results_and_uses_the_first(): void
    {
        $report = (new AggregateReportParser)->parseFile($this->fixture('multi-record-multi-auth.xml'));

        $this->assertEquals('quarantine', $report->policy_p);
        $this->assertNull($report->policy_sp);
        $this->assertEquals(50, $report->policy_pct);
        $this->assertEquals('s', $report->policy_adkim);
        $this->assertEquals('s', $report->policy_aspf);

        $this->assertCount(2, $report->records);

        $first = $report->records->firstWhere('source_ip', '40.92.90.104');
        $this->assertEquals(5, $first->count);
        $this->assertEquals('example.org', $first->dkim_domain);
        $this->assertEquals('selector1', $first->dkim_selector);
        $this->assertEquals('bounce.example.org', $first->envelope_from);
        $this->assertEquals('recipient.example.net', $first->envelope_to);
        $this->assertEquals('mfrom', $first->spf_scope);

        $second = $report->records->firstWhere('source_ip', '203.0.113.55');
        $this->assertEquals('quarantine', $second->disposition);
        $this->assertEquals('fail', $second->dkim_result);
        $this->assertEquals('fail', $second->spf_result);
    }

    public function test_it_handles_a_report_with_no_spf_auth_result_element(): void
    {
        $report = (new AggregateReportParser)->parseFile($this->fixture('no-spf-element.xml'));

        $record = $report->records->first();
        $this->assertNull($record->spf_domain);
        $this->assertNull($record->spf_scope);
        $this->assertNull($record->spf_auth_result);
        $this->assertEquals('pass', $record->spf_result);
    }

    public function test_it_extracts_a_zipped_report(): void
    {
        $report = (new AggregateReportParser)->parseFile($this->fixture('google-single-record.xml.zip'));

        $this->assertEquals('google.com', $report->org_name);
        $this->assertCount(1, $report->records);
    }

    public function test_it_normalizes_a_non_standard_version_identifier(): void
    {
        // Amazon SES has been observed sending <version>0.1</version> instead
        // of the "1.0" the parser strictly expects, despite the report itself
        // being a perfectly valid, otherwise-standard aggregate report.
        $report = (new AggregateReportParser)->parseFile($this->fixture('non-standard-version.xml'));

        $this->assertEquals('AMAZON-SES', $report->org_name);
        $this->assertCount(1, $report->records);
    }

    public function test_it_normalizes_non_standard_result_casing(): void
    {
        // KDDI/au.com has been observed sending <result>Fail</result> instead
        // of the lowercase "fail" the parser's enums strictly require.
        $report = (new AggregateReportParser)->parseFile($this->fixture('non-standard-case.xml'));

        $record = $report->records->first();
        $this->assertEquals('fail', $record->spf_result);
        $this->assertEquals('fail', $record->spf_auth_result);
        $this->assertEquals('none', $record->dkim_auth_result);
    }

    public function test_reprocessing_the_same_report_is_idempotent(): void
    {
        $parser = new AggregateReportParser;

        $first = $parser->parseFile($this->fixture('google-single-record.xml'));
        $second = $parser->parseFile($this->fixture('google-single-record.xml'));

        $this->assertEquals($first->id, $second->id);
        $this->assertDatabaseCount('aggregate_reports', 1);
        $this->assertDatabaseCount('aggregate_report_records', 1);
    }

    public function test_reprocessing_replaces_stale_records_instead_of_duplicating(): void
    {
        $parser = new AggregateReportParser;

        $parser->parseFile($this->fixture('multi-record-multi-auth.xml'));
        $this->assertDatabaseCount('aggregate_report_records', 2);

        // Re-parse the same report id/org — records should be replaced, not appended.
        $parser->parseFile($this->fixture('multi-record-multi-auth.xml'));
        $this->assertDatabaseCount('aggregate_report_records', 2);
    }

    public function test_a_corrupt_file_throws_rather_than_silently_succeeding(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dmarc').'.xml';
        file_put_contents($path, '<feedback><not-valid-dmarc/>');

        $this->expectException(\Throwable::class);

        (new AggregateReportParser)->parseFile($path);

        @unlink($path);
    }

    public function test_it_dispatches_an_enrichment_job_after_storing_a_report(): void
    {
        $report = (new AggregateReportParser)->parseFile($this->fixture('google-single-record.xml'));

        Queue::assertPushed(EnrichReportRecordsJob::class, fn ($job) => $job->aggregateReportId === $report->id);
    }
}
