<?php

namespace Tests\Unit;

use App\Models\Domain;
use App\Services\Analytics\DmarcMetricsService;
use App\Services\Dmarc\AggregateReportParser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DmarcMetricsServiceTest extends TestCase
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

    public function test_summary_computes_pass_rates_across_domains(): void
    {
        // google-single-record.xml: example.com, 1 record, count=2, dkim=pass, spf=pass
        (new AggregateReportParser)->parseFile($this->fixture('google-single-record.xml'));
        // multi-record-multi-auth.xml: example.org, records count=5 (dkim pass/spf fail) and count=1 (dkim fail/spf fail)
        (new AggregateReportParser)->parseFile($this->fixture('multi-record-multi-auth.xml'));

        $service = new DmarcMetricsService;
        $from = Carbon::createFromTimestamp(1735689600)->subDay();
        $to = Carbon::createFromTimestamp(1735689600)->addDay();

        $summary = $service->summary(null, $from, $to);

        // total = 2 + 5 + 1 = 8
        $this->assertEquals(8, $summary['total']);
        // dmarc pass (dkim pass OR spf pass): record1(2, both pass)=pass, record2(5, dkim pass spf fail)=pass, record3(1, both fail)=fail
        // pass = 2 + 5 = 7 -> 7/8 = 87.5%
        $this->assertEquals(87.5, $summary['dmarc_pass_pct']);
        // spf pass = only record1 (2) -> 2/8 = 25%
        $this->assertEquals(25.0, $summary['spf_pass_pct']);
        // dkim pass = record1(2) + record2(5) = 7/8 = 87.5%
        $this->assertEquals(87.5, $summary['dkim_pass_pct']);
        $this->assertEquals(3, $summary['distinct_sources']);
    }

    public function test_summary_filters_by_domain(): void
    {
        (new AggregateReportParser)->parseFile($this->fixture('google-single-record.xml'));
        (new AggregateReportParser)->parseFile($this->fixture('multi-record-multi-auth.xml'));

        $exampleCom = Domain::where('fqdn', 'example.com')->firstOrFail();

        $service = new DmarcMetricsService;
        $from = Carbon::createFromTimestamp(1735689600)->subDay();
        $to = Carbon::createFromTimestamp(1735689600)->addDay();

        $summary = $service->summary($exampleCom->id, $from, $to);

        $this->assertEquals(2, $summary['total']);
        $this->assertEquals(100.0, $summary['dmarc_pass_pct']);
    }

    public function test_summary_filters_by_organisation(): void
    {
        (new AggregateReportParser)->parseFile($this->fixture('google-single-record.xml'));
        (new AggregateReportParser)->parseFile($this->fixture('multi-record-multi-auth.xml'));

        $org = \App\Models\Organisation::create(['name' => 'Acme']);
        $exampleCom = Domain::where('fqdn', 'example.com')->firstOrFail();
        $exampleCom->update(['organisation_id' => $org->id]);

        // example.org (from the other fixture) stays unassigned.
        $service = new DmarcMetricsService;
        $from = Carbon::createFromTimestamp(1735689600)->subDay();
        $to = Carbon::createFromTimestamp(1735689600)->addDay();

        $summary = $service->summary(null, $from, $to, $org->id);

        $this->assertEquals(2, $summary['total']);
        $this->assertEquals(100.0, $summary['dmarc_pass_pct']);
    }

    public function test_summary_returns_zeroes_when_no_data_in_window(): void
    {
        (new AggregateReportParser)->parseFile($this->fixture('google-single-record.xml'));

        $service = new DmarcMetricsService;
        $from = Carbon::now()->addYear();
        $to = Carbon::now()->addYear()->addDay();

        $summary = $service->summary(null, $from, $to);

        $this->assertEquals(0, $summary['total']);
        $this->assertEquals(0.0, $summary['dmarc_pass_pct']);
        $this->assertEquals(0, $summary['distinct_sources']);
    }

    public function test_trend_groups_by_day(): void
    {
        (new AggregateReportParser)->parseFile($this->fixture('google-single-record.xml'));

        $service = new DmarcMetricsService;
        $from = Carbon::createFromTimestamp(1735689600)->subDay();
        $to = Carbon::createFromTimestamp(1735689600)->addDay();

        $trend = $service->trend(null, $from, $to);

        $this->assertCount(1, $trend);
        $this->assertEquals('2025-01-01', $trend->first()['date']);
        $this->assertEquals(2, $trend->first()['total']);
        $this->assertEquals(100.0, $trend->first()['dmarc_pass_pct']);
    }

    public function test_source_breakdown_orders_by_volume_and_flags_enforcement(): void
    {
        (new AggregateReportParser)->parseFile($this->fixture('multi-record-multi-auth.xml'));

        $service = new DmarcMetricsService;
        $from = Carbon::createFromTimestamp(1735689600)->subDay();
        $to = Carbon::createFromTimestamp(1735689600)->addDay();

        $sources = $service->sourceBreakdown(null, $from, $to);

        $this->assertCount(2, $sources);
        // Highest volume source (count=5) should be first
        $first = $sources->first();
        $this->assertEquals('40.92.90.104', $first['source_ip']);
        $this->assertEquals(5, $first['total']);
        $this->assertEquals(0, $first['enforced']); // disposition=none
        $this->assertEquals(0.0, $first['spf_pass_pct']); // spf=fail
        $this->assertEquals(100.0, $first['dkim_pass_pct']); // dkim=pass

        $second = $sources->last();
        $this->assertEquals('203.0.113.55', $second['source_ip']);
        $this->assertEquals(1, $second['enforced']); // disposition=quarantine
        $this->assertEquals(0.0, $second['spf_pass_pct']); // spf=fail
        $this->assertEquals(0.0, $second['dkim_pass_pct']); // dkim=fail
    }
}
