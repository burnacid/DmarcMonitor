<?php

namespace Tests\Unit;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Services\Dns\DomainAuthenticationChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DomainAuthenticationCheckerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, array<int, string>>  $records  hostname => TXT record strings
     */
    private function checker(array $records): DomainAuthenticationChecker
    {
        return new DomainAuthenticationChecker(fn (string $hostname) => $records[$hostname] ?? []);
    }

    public function test_it_reports_a_strong_dmarc_policy_as_valid(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([
            '_dmarc.example.com' => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.com'],
        ])->check($domain);

        $this->assertEquals('valid', $result['dmarc_status']);
        $this->assertStringContainsString('p=reject', $result['dmarc_record']);
    }

    public function test_it_reports_a_monitoring_only_dmarc_policy_as_weak(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([
            '_dmarc.example.com' => ['v=DMARC1; p=none'],
        ])->check($domain);

        $this->assertEquals('weak', $result['dmarc_status']);
    }

    public function test_it_reports_a_missing_dmarc_record_as_missing(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([])->check($domain);

        $this->assertEquals('missing', $result['dmarc_status']);
        $this->assertNull($result['dmarc_record']);
    }

    public function test_it_reports_a_valid_spf_record(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([
            'example.com' => ['v=spf1 include:_spf.example.com ~all'],
        ])->check($domain);

        $this->assertEquals('valid', $result['spf_status']);
    }

    public function test_it_reports_a_missing_spf_record(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([])->check($domain);

        $this->assertEquals('missing', $result['spf_status']);
        $this->assertNull($result['spf_record']);
    }

    public function test_dkim_is_unknown_when_no_selector_has_ever_been_seen(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([])->check($domain);

        $this->assertEquals('unknown', $result['dkim_status']);
        $this->assertNull($result['dkim_selector']);
    }

    public function test_dkim_is_valid_when_a_seen_selector_resolves(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $this->recordDkimSelector($domain, 'selector1');

        $result = $this->checker([
            'selector1._domainkey.example.com' => ['v=DKIM1; k=rsa; p=MIGfMA0...'],
        ])->check($domain);

        $this->assertEquals('valid', $result['dkim_status']);
        $this->assertEquals('selector1', $result['dkim_selector']);
    }

    public function test_every_seen_selector_that_resolves_is_configured(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $this->recordDkimSelector($domain, 'older', now()->subDays(10));
        $this->recordDkimSelector($domain, 'newer', now()->subDay());
        $this->recordDkimSelector($domain, 'gone', now()->subDays(3));

        $result = $this->checker([
            'older._domainkey.example.com' => ['v=DKIM1; k=rsa; p=old'],
            'newer._domainkey.example.com' => ['v=DKIM1; k=rsa; p=new'],
        ])->check($domain);

        $this->assertEquals('valid', $result['dkim_status']);
        $this->assertEquals('newer', $result['dkim_selector']);
        $this->assertEquals('v=DKIM1; k=rsa; p=new', $result['dkim_record']);
        $this->assertEquals([
            ['selector' => 'newer', 'record' => 'v=DKIM1; k=rsa; p=new'],
            ['selector' => 'older', 'record' => 'v=DKIM1; k=rsa; p=old'],
        ], $result['dkim_selectors']);
    }

    public function test_a_selector_whose_record_was_removed_is_no_longer_configured_on_the_next_check(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $this->recordDkimSelector($domain, 'one');
        $this->recordDkimSelector($domain, 'two');

        $this->checker([
            'one._domainkey.example.com' => ['v=DKIM1; p=1'],
            'two._domainkey.example.com' => ['v=DKIM1; p=2'],
        ])->checkAndStore($domain);

        $this->assertCount(2, $domain->fresh()->configuredDkimSelectors());

        $this->checker([
            'one._domainkey.example.com' => ['v=DKIM1; p=1'],
        ])->checkAndStore($domain);

        $this->assertSame(['one'], array_column($domain->fresh()->configuredDkimSelectors(), 'selector'));

        $this->checker([])->checkAndStore($domain);

        $fresh = $domain->fresh();
        $this->assertEquals('missing', $fresh->dkim_status);
        $this->assertSame([], $fresh->configuredDkimSelectors());
    }

    public function test_dkim_is_missing_when_the_seen_selector_does_not_resolve(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $this->recordDkimSelector($domain, 'selector1');

        $result = $this->checker([])->check($domain);

        $this->assertEquals('missing', $result['dkim_status']);
        $this->assertEquals('selector1', $result['dkim_selector']);
    }

    public function test_dkim_selectors_from_other_domains_signing_are_ignored(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);
        // A third-party sender's DKIM domain shouldn't be treated as this domain's own selector.
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'dkim_domain' => 'thirdparty.example',
            'dkim_selector' => 'their-selector',
        ]);

        $result = $this->checker([])->check($domain);

        $this->assertEquals('unknown', $result['dkim_status']);
    }

    public function test_check_and_store_persists_the_result_on_the_domain(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $this->checker([
            '_dmarc.example.com' => ['v=DMARC1; p=reject'],
            'example.com' => ['v=spf1 -all'],
        ])->checkAndStore($domain);

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'dmarc_status' => 'valid',
            'spf_status' => 'valid',
            'dkim_status' => 'unknown',
        ]);
        $this->assertNotNull($domain->fresh()->dns_checked_at);
    }

    private function recordDkimSelector(Domain $domain, string $selector, ?Carbon $seenAt = null): void
    {
        $report = AggregateReport::factory()->create(array_filter([
            'domain_id' => $domain->id,
            'date_range_end' => $seenAt,
        ]));
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'dkim_domain' => $domain->fqdn,
            'dkim_selector' => $selector,
        ]);
    }
}
