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
     * @param  array<string, array<string, int>>  $mx  hostname => [MX host => preference]
     */
    private function checker(array $records, array $mx = []): DomainAuthenticationChecker
    {
        return new DomainAuthenticationChecker(
            fn (string $hostname) => $records[$hostname] ?? [],
            fn (string $hostname) => $mx[$hostname] ?? [],
        );
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

    public function test_it_counts_spf_lookups_through_nested_includes(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([
            'example.com' => ['v=spf1 a mx ip4:192.0.2.1 include:_spf.vendor.test -all'],
            '_spf.vendor.test' => ['v=spf1 include:_netblocks.vendor.test exists:%{i}.check.vendor.test ~all'],
            '_netblocks.vendor.test' => ['v=spf1 ip4:198.51.100.0/24 ~all'],
        ])->check($domain);

        $this->assertEquals('valid', $result['spf_status']);
        $this->assertSame(5, $result['spf_lookup_count']);
    }

    public function test_it_flags_an_spf_record_needing_more_than_ten_lookups(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $includes = collect(range(1, 6))->map(fn (int $i) => "include:_spf{$i}.vendor.test")->implode(' ');

        $result = $this->checker(array_merge(
            ['example.com' => ["v=spf1 mx {$includes} ~all"]],
            collect(range(1, 6))->mapWithKeys(fn (int $i) => ["_spf{$i}.vendor.test" => ['v=spf1 a ~all']])->all(),
        ))->check($domain);

        $this->assertEquals('too_many_lookups', $result['spf_status']);
        $this->assertGreaterThan(10, $result['spf_lookup_count']);
    }

    public function test_a_split_spf_record_is_joined_and_valid_when_split_inside_a_term(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([
            'example.com' => [['v=spf1 include:_spf.goo', 'gle.com include:spf.protection.outlook.com ', 'include:mail.test -all']],
        ])->check($domain);

        $this->assertEquals('valid', $result['spf_status']);
        $this->assertSame('v=spf1 include:_spf.google.com include:spf.protection.outlook.com include:mail.test -all', $result['spf_record']);
    }

    public function test_it_flags_a_split_spf_record_that_glues_two_terms_together(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([
            'example.com' => [['v=spf1 include:_spf.google.com', 'include:spf.protection.outlook.com -all']],
        ])->check($domain);

        $this->assertEquals('broken_split', $result['spf_status']);
    }

    public function test_an_spf_include_loop_is_flagged_instead_of_recursing_forever(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([
            'example.com' => ['v=spf1 include:loop.vendor.test -all'],
            'loop.vendor.test' => ['v=spf1 include:example.com -all'],
        ])->check($domain);

        $this->assertEquals('too_many_lookups', $result['spf_status']);
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

    public function test_the_selectors_of_a_mail_provider_detected_from_the_mx_records_are_checked_without_reports(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker(
            ['selector1._domainkey.example.com' => ['v=DKIM1; k=rsa; p=one']],
            ['example.com' => ['example-com.mail.protection.outlook.com' => 0]],
        )->check($domain);

        $this->assertEquals('microsoft365', $result['mail_provider']);
        $this->assertEquals('valid', $result['dkim_status']);
        $this->assertEquals('selector1', $result['dkim_selector']);
        $this->assertSame([
            ['selector' => 'selector1', 'found' => true],
            ['selector' => 'selector2', 'found' => false],
        ], $result['dkim_expected_selectors']);
    }

    public function test_dkim_is_missing_when_none_of_the_detected_providers_selectors_resolve(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([], ['example.com' => ['aspmx.l.google.com' => 1, 'alt1.aspmx.l.google.com' => 5]])->check($domain);

        $this->assertEquals('google_workspace', $result['mail_provider']);
        $this->assertEquals('missing', $result['dkim_status']);
        $this->assertSame([['selector' => 'google', 'found' => false]], $result['dkim_expected_selectors']);
    }

    public function test_a_domain_whose_mx_is_not_a_known_provider_has_no_expected_selectors(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $result = $this->checker([], ['example.com' => ['mx.example.com' => 10]])->check($domain);

        $this->assertNull($result['mail_provider']);
        $this->assertNull($result['dkim_expected_selectors']);
        $this->assertEquals('unknown', $result['dkim_status']);
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

    public function test_check_and_store_keeps_a_changed_or_removed_record_in_the_history(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);

        $this->checker([
            '_dmarc.example.com' => ['v=DMARC1; p=none'],
            'example.com' => ['v=spf1 -all'],
        ])->checkAndStore($domain);

        $this->assertDatabaseCount('domain_record_histories', 0);

        $this->checker([
            '_dmarc.example.com' => ['v=DMARC1; p=reject'],
        ])->checkAndStore($domain);

        $this->assertSame(
            [['type' => 'dmarc', 'record' => 'v=DMARC1; p=none'], ['type' => 'spf', 'record' => 'v=spf1 -all']],
            $domain->recordHistories()->reorder('type')->get(['type', 'record'])->map->only(['type', 'record'])->all(),
        );
    }

    public function test_check_and_store_adds_no_history_when_the_records_are_unchanged(): void
    {
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $checker = $this->checker([
            '_dmarc.example.com' => ['v=DMARC1; p=reject'],
            'example.com' => ['v=spf1 -all'],
        ]);

        $checker->checkAndStore($domain);
        $checker->checkAndStore($domain);

        $this->assertDatabaseCount('domain_record_histories', 0);
    }

    public function test_it_checks_the_authorisation_record_of_each_external_report_domain(): void
    {
        config(['dmarc.rua_address' => null]);
        $domain = Domain::factory()->create(['fqdn' => 'client.test']);

        $result = $this->checker([
            '_dmarc.client.test' => ['v=DMARC1; p=none; rua=mailto:dmarc@msp.test,mailto:reports@vendor.test,mailto:postmaster@client.test; ruf=mailto:forensic@msp.test'],
            'client.test._report._dmarc.msp.test' => ['v=DMARC1'],
        ])->check($domain);

        $this->assertSame([
            ['report_domain' => 'msp.test', 'host' => 'client.test._report._dmarc.msp.test', 'authorized' => true, 'in_record' => true],
            ['report_domain' => 'vendor.test', 'host' => 'client.test._report._dmarc.vendor.test', 'authorized' => false, 'in_record' => true],
        ], $result['dmarc_report_authorizations']);
    }

    public function test_it_checks_this_apps_report_address_before_the_record_lists_it(): void
    {
        config(['dmarc.rua_address' => 'dmarc@msp.test']);
        $domain = Domain::factory()->create(['fqdn' => 'client.test']);

        $result = $this->checker([])->check($domain);

        $this->assertSame([
            ['report_domain' => 'msp.test', 'host' => 'client.test._report._dmarc.msp.test', 'authorized' => false, 'in_record' => false],
        ], $result['dmarc_report_authorizations']);
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
