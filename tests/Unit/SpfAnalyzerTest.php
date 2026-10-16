<?php

namespace Tests\Unit;

use App\Services\Dns\SpfAnalyzer;
use Tests\TestCase;

class SpfAnalyzerTest extends TestCase
{
    /**
     * @param  array<string, list<string>>  $txt  hostname => SPF/TXT records
     * @param  array<string, list<string>>  $addresses  hostname => A/AAAA addresses
     * @param  array<string, list<string>>  $mx  hostname => MX hosts
     */
    private function analyzer(array $txt, array $addresses = [], array $mx = []): SpfAnalyzer
    {
        return new SpfAnalyzer(fn (string $hostname, int $type): array => match ($type) {
            DNS_TXT => $txt[$hostname] ?? [],
            DNS_A => array_values(array_filter($addresses[$hostname] ?? [], fn (string $ip) => ! str_contains($ip, ':'))),
            DNS_AAAA => array_values(array_filter($addresses[$hostname] ?? [], fn (string $ip) => str_contains($ip, ':'))),
            DNS_MX => $mx[$hostname] ?? [],
            default => [],
        });
    }

    /**
     * Twelve includes of one lookup each, every one authorising a single address.
     *
     * @return array<string, list<string>>
     */
    private function twelveVendors(): array
    {
        $txt = [];

        foreach (range(1, 12) as $i) {
            $txt["vendor{$i}.test"] = ["v=spf1 ip4:198.51.100.{$i} -all"];
        }

        return $txt;
    }

    public function test_it_counts_lookups_through_nested_includes(): void
    {
        $analysis = $this->analyzer([
            'outer.test' => ['v=spf1 include:inner.test a mx -all'],
            'inner.test' => ['v=spf1 ip4:192.0.2.1 -all'],
        ])->analyze('v=spf1 include:outer.test ip4:203.0.113.5 ~all redirect=ignored.test', 'example.com');

        $this->assertSame(4, $analysis['lookups']);
        $this->assertSame(4, $analysis['terms'][0]['lookups']);
        $this->assertSame(['192.0.2.1'], $analysis['terms'][0]['children'][0]['ranges']);
        $this->assertTrue($analysis['terms'][3]['ignored']);
    }

    public function test_an_include_without_an_spf_record_is_flagged_as_a_void_lookup(): void
    {
        $analysis = $this->analyzer([])->analyze('v=spf1 include:gone.test -all', 'example.com');

        $this->assertTrue($analysis['terms'][0]['void']);
        $this->assertNotNull($analysis['terms'][0]['error']);
        $this->assertSame(1, $analysis['void_lookups']);
    }

    public function test_it_warns_about_a_permissive_all(): void
    {
        $analysis = $this->analyzer([])->analyze('v=spf1 ip4:192.0.2.1 +all', 'example.com');

        $this->assertStringContainsString('+all', implode(' ', $analysis['warnings']));
    }

    public function test_it_suggests_removing_duplicate_includes_ptr_and_broken_includes(): void
    {
        $suggestions = collect($this->analyzer([
            'big.test' => ['v=spf1 include:small.test -all'],
            'small.test' => ['v=spf1 ip4:192.0.2.1 -all'],
        ])->suggestions('v=spf1 include:big.test include:small.test ptr include:gone.test -all', 'example.com'))->keyBy('key');

        $this->assertSame('v=spf1 include:big.test ptr include:gone.test -all', $suggestions['duplicates']['record']);
        $this->assertSame(1, $suggestions['duplicates']['saves']);
        $this->assertSame('v=spf1 include:big.test include:small.test include:gone.test -all', $suggestions['ptr']['record']);
        $this->assertSame('v=spf1 include:big.test include:small.test ptr -all', $suggestions['broken']['record']);
    }

    public function test_it_suggests_replacing_a_and_mx_with_their_addresses(): void
    {
        $suggestions = collect($this->analyzer(
            [],
            ['example.com' => ['192.0.2.10', '2001:db8::10'], 'mx1.example.com' => ['192.0.2.20']],
            ['example.com' => ['mx1.example.com']],
        )->suggestions('v=spf1 a mx/24 -all', 'example.com'))->keyBy('key');

        $this->assertSame('v=spf1 ip4:192.0.2.10 ip6:2001:db8::10 mx/24 -all', $suggestions['resolve:0']['record']);
        $this->assertSame('v=spf1 a ip4:192.0.2.20/24 -all', $suggestions['resolve:1']['record']);
    }

    public function test_it_only_suggests_removing_an_include_when_no_reported_source_is_in_its_ranges(): void
    {
        $analyzer = $this->analyzer([
            'used.test' => ['v=spf1 ip4:192.0.2.0/24 -all'],
            'unused.test' => ['v=spf1 ip4:198.51.100.0/24 -all'],
        ]);
        $record = 'v=spf1 include:used.test include:unused.test -all';

        $keys = array_column($analyzer->suggestions($record, 'example.com', ['192.0.2.44']), 'key');

        $this->assertContains('unused:unused.test', $keys);
        $this->assertNotContains('unused:used.test', $keys);
        $this->assertNotContains('unused:unused.test', array_column($analyzer->suggestions($record, 'example.com'), 'key'));
    }

    public function test_an_include_using_exists_is_never_flattened(): void
    {
        $keys = array_column($this->analyzer([
            'dynamic.test' => ['v=spf1 exists:%{i}.allow.dynamic.test -all'],
        ])->suggestions('v=spf1 include:dynamic.test -all', 'example.com'), 'key');

        $this->assertNotContains('flatten:dynamic.test', $keys);
    }

    public function test_the_combined_fix_brings_a_record_over_the_limit_within_it(): void
    {
        $record = 'v=spf1 '.implode(' ', array_map(fn (int $i) => "include:vendor{$i}.test", range(1, 12))).' -all';
        $analyzer = $this->analyzer($this->twelveVendors());

        $this->assertSame(12, $analyzer->analyze($record, 'example.com')['lookups']);

        $fit = $analyzer->suggestions($record, 'example.com')[0];

        $this->assertSame('fit', $fit['key']);
        $this->assertSame(10, $fit['lookups']);
        $this->assertSame(10, $analyzer->analyze($fit['record'], 'example.com')['lookups']);
        $this->assertStringContainsString('ip4:198.51.100.', $fit['record']);
    }

    public function test_an_include_loop_counts_as_over_the_limit(): void
    {
        $analysis = $this->analyzer([
            'loop.test' => ['v=spf1 include:example.com -all'],
        ])->analyze('v=spf1 include:loop.test -all', 'example.com');

        $this->assertGreaterThan(10, $analysis['lookups']);
    }
}
