<?php

namespace Tests\Unit;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Services\Analytics\DomainHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DomainHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private function healthyDomain(string $record = 'v=DMARC1; p=none; rua=mailto:dmarc@msp.test', array $attributes = []): Domain
    {
        return Domain::factory()->create(array_merge([
            'created_at' => now()->subDays(60),
            'dmarc_status' => str_contains($record, 'p=none') ? 'weak' : 'valid',
            'dmarc_record' => $record,
            'spf_status' => 'valid',
            'dkim_status' => 'valid',
        ], $attributes));
    }

    private function addReport(Domain $domain, int $passing, int $failing = 0, ?Carbon $begin = null, ?Carbon $ingestedAt = null): void
    {
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_begin' => $begin ?? now()->subDays(2),
            'date_range_end' => ($begin ?? now()->subDays(2))->copy()->addDay(),
            'created_at' => $ingestedAt ?? now()->subDay(),
        ]);

        if ($passing > 0) {
            AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'count' => $passing]);
        }

        if ($failing > 0) {
            AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'count' => $failing, 'dkim_result' => 'fail', 'spf_result' => 'fail']);
        }
    }

    private function health(Domain $domain, int $days = 30): array
    {
        return app(DomainHealthService::class)->forDomains(collect([$domain]), $days)->get($domain->id);
    }

    public function test_domain_with_enough_clean_history_on_p_none_is_ready_for_quarantine(): void
    {
        config(['dmarc.rua_address' => 'dmarc@msp.test']);
        $domain = $this->healthyDomain();
        $this->addReport($domain, 150, begin: now()->subDays(20));
        $this->addReport($domain, 100);

        $health = $this->health($domain);

        $this->assertSame([], $health['issues']);
        $this->assertSame('quarantine', $health['readiness']['step']);
        $this->assertTrue($health['readiness']['ready']);
        $this->assertSame(250, $health['total']);
    }

    public function test_readiness_lists_the_checks_that_block_the_next_policy(): void
    {
        $domain = $this->healthyDomain('v=DMARC1; p=quarantine', ['dkim_status' => 'missing']);
        $this->addReport($domain, 97, 3, begin: now()->subDays(5));

        $readiness = $this->health($domain)['readiness'];

        $this->assertSame('reject', $readiness['step']);
        $this->assertFalse($readiness['ready']);
        $failed = collect($readiness['checks'])->reject(fn (array $check) => $check['passed'])->pluck('label')->all();
        $this->assertSame([
            '5 days of reports (needs 14)',
            'DMARC pass rate 97% (needs 99%)',
            'DKIM record found',
        ], $failed);
    }

    public function test_readiness_steps_follow_the_published_policy(): void
    {
        $this->assertSame('publish', $this->health($this->healthyDomain('', ['dmarc_record' => null, 'dmarc_status' => 'missing']))['readiness']['step']);
        $this->assertSame('raise_pct', $this->health($this->healthyDomain('v=DMARC1; p=quarantine; pct=25'))['readiness']['step']);
        $this->assertSame('enforced', $this->health($this->healthyDomain('v=DMARC1; p=reject'))['readiness']['step']);
    }

    public function test_active_domain_without_recent_reports_is_flagged_after_the_grace_period(): void
    {
        $stale = $this->healthyDomain();
        $this->addReport($stale, 50, ingestedAt: now()->subDays(10));
        $new = $this->healthyDomain(attributes: ['created_at' => now()->subDay()]);
        $inactive = $this->healthyDomain(attributes: ['is_active' => false]);
        $neverReported = $this->healthyDomain();

        $this->assertContains('no_reports', $this->health($stale)['issues']);
        $this->assertSame([], $this->health($stale)['remarks']);
        $this->assertNotContains('no_reports', $this->health($new)['issues']);
        $this->assertSame([], $this->health($inactive)['issues']);
        $this->assertContains('no_reports', $this->health($neverReported)['issues']);
        $this->assertSame([], $this->health($neverReported)['remarks']);
    }

    public function test_domain_that_rarely_sends_gets_a_remark_instead_of_a_no_reports_issue(): void
    {
        $rarelySends = $this->healthyDomain();
        foreach ([80, 61, 45, 33, 21] as $daysAgo) {
            $this->addReport($rarelySends, 3, begin: now()->subDays($daysAgo), ingestedAt: now()->subDays($daysAgo - 1));
        }

        $sendsDaily = $this->healthyDomain();
        foreach (range(40, 10) as $daysAgo) {
            $this->addReport($sendsDaily, 3, begin: now()->subDays($daysAgo), ingestedAt: now()->subDays($daysAgo - 1));
        }

        $health = $this->health($rarelySends);
        $this->assertNotContains('no_reports', $health['issues']);
        $this->assertSame(['rarely_sends'], $health['remarks']);

        $this->assertContains('no_reports', $this->health($sendsDaily)['issues']);
        $this->assertSame([], $this->health($sendsDaily)['remarks']);
    }

    public function test_never_reported_domain_whose_record_reports_here_gets_a_remark_instead_of_a_no_reports_issue(): void
    {
        config(['dmarc.rua_address' => 'dmarc@msp.test']);
        $reportsHere = $this->healthyDomain();
        $reportsElsewhere = $this->healthyDomain('v=DMARC1; p=none; rua=mailto:reports@vendor.test');

        $health = $this->health($reportsHere);
        $this->assertSame([], $health['issues']);
        $this->assertSame(['no_mail_seen'], $health['remarks']);

        $this->assertContains('no_reports', $this->health($reportsElsewhere)['issues']);
        $this->assertSame([], $this->health($reportsElsewhere)['remarks']);
    }

    public function test_dns_problems_low_pass_rate_open_alerts_and_foreign_rua_are_issues(): void
    {
        config(['dmarc.rua_address' => 'dmarc@msp.test']);
        $domain = $this->healthyDomain('v=DMARC1; p=none; rua=mailto:reports@vendor.test', ['spf_status' => 'missing']);
        $this->addReport($domain, 5, 15);
        AlertEvent::factory()->create(['alert_rule_id' => AlertRule::factory(), 'domain_id' => $domain->id]);
        AlertEvent::factory()->resolved()->create(['alert_rule_id' => AlertRule::factory(), 'domain_id' => $domain->id]);

        $health = $this->health($domain);

        $this->assertSame(['spf_missing', 'low_pass_rate', 'open_alerts', 'not_reporting_here'], $health['issues']);
        $this->assertSame(1, $health['open_alerts']);
        $this->assertSame('critical', $health['pass_status']);
    }

    public function test_missing_report_authorisation_is_an_issue_only_for_addresses_in_the_record(): void
    {
        $authorization = fn (bool $authorized, bool $inRecord) => [
            'report_domain' => 'msp.test', 'host' => 'x._report._dmarc.msp.test', 'authorized' => $authorized, 'in_record' => $inRecord,
        ];
        $missing = $this->healthyDomain(attributes: ['dmarc_report_authorizations' => [$authorization(false, true)]]);
        $notListedYet = $this->healthyDomain(attributes: ['dmarc_report_authorizations' => [$authorization(false, false)]]);
        $found = $this->healthyDomain(attributes: ['dmarc_report_authorizations' => [$authorization(true, true)]]);

        $this->assertContains('report_auth_missing', $this->health($missing)['issues']);
        $this->assertNotContains('report_auth_missing', $this->health($notListedYet)['issues']);
        $this->assertNotContains('report_auth_missing', $this->health($found)['issues']);
    }

    public function test_roll_up_weights_pass_rate_by_volume_and_counts_policies(): void
    {
        $a = $this->healthyDomain('v=DMARC1; p=reject');
        $b = $this->healthyDomain();
        $this->addReport($a, 90, 10);
        $this->addReport($b, 10, 0);

        $service = app(DomainHealthService::class);
        $summary = $service->rollUp($service->forDomains(collect([$a, $b])));

        $this->assertSame(2, $summary['domains']);
        $this->assertSame(110, $summary['total']);
        $this->assertSame(90.9, $summary['dmarc_pass_pct']);
        $this->assertSame(['reject' => 1, 'none' => 1], $summary['policies']);
    }
}
