<?php

namespace Tests\Unit;

use App\Services\Smtp\AllowedSources;
use App\Support\SpfRangeResolver;
use Tests\TestCase;

class AllowedSourcesTest extends TestCase
{
    /**
     * @param  array<string, list<string>>  $records
     */
    private function resolver(array &$records): SpfRangeResolver
    {
        return new SpfRangeResolver(function (string $domain) use (&$records): array {
            return $records[$domain] ?? [];
        });
    }

    public function test_the_resolver_follows_includes_and_redirects_and_ignores_other_mechanisms(): void
    {
        $records = [
            'example.com' => ['google-site-verification=abc', 'v=spf1 ip4:192.0.2.0/24 include:_spf.example.net a mx -all'],
            '_spf.example.net' => ['v=spf1 ip6:2001:db8::/32 ip4:198.51.100.7 redirect=other.example.net'],
            'other.example.net' => ['v=spf1 +ip4:203.0.113.0/25 -ip4:10.0.0.1 ~all'],
        ];

        $ranges = $this->resolver($records)->resolve('Example.com');

        $this->assertEqualsCanonicalizing(
            ['192.0.2.0/24', '2001:db8::/32', '198.51.100.7', '203.0.113.0/25'],
            $ranges,
        );
    }

    public function test_the_resolver_survives_include_loops(): void
    {
        $records = [
            'a.example' => ['v=spf1 include:b.example ip4:192.0.2.1'],
            'b.example' => ['v=spf1 include:a.example ip4:192.0.2.2'],
        ];

        $this->assertEqualsCanonicalizing(['192.0.2.1', '192.0.2.2'], $this->resolver($records)->resolve('a.example'));
    }

    public function test_it_combines_static_entries_with_spf_ranges(): void
    {
        $records = ['provider.example' => ['v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 -all']];
        $sources = new AllowedSources(['10.0.0.0/8', 'spf:provider.example'], $this->resolver($records));

        $this->assertTrue($sources->allows('10.1.2.3'));
        $this->assertTrue($sources->allows('192.0.2.55'));
        $this->assertTrue($sources->allows('2001:db8::1'));
        $this->assertFalse($sources->allows('198.51.100.1'));
        $this->assertSame(2, $sources->resolvedRangeCount());
    }

    public function test_ranges_are_refreshed_when_they_go_stale(): void
    {
        $records = ['provider.example' => ['v=spf1 ip4:192.0.2.0/24']];
        $sources = new AllowedSources(['spf:provider.example'], $this->resolver($records), refreshSeconds: 0);

        $this->assertTrue($sources->allows('192.0.2.1'));

        $records['provider.example'] = ['v=spf1 ip4:198.51.100.0/24'];

        $this->assertTrue($sources->allows('198.51.100.1'));
        $this->assertFalse($sources->allows('192.0.2.1'));
    }

    public function test_previous_ranges_are_kept_when_a_refresh_resolves_nothing(): void
    {
        $records = ['provider.example' => ['v=spf1 ip4:192.0.2.0/24']];
        $sources = new AllowedSources(['spf:provider.example'], $this->resolver($records), refreshSeconds: 0);

        $this->assertTrue($sources->allows('192.0.2.1'));

        $records = [];

        $this->assertTrue($sources->allows('192.0.2.1'));
    }

    public function test_nothing_is_allowed_when_spf_cannot_be_resolved_at_all(): void
    {
        $records = [];
        $sources = new AllowedSources(['spf:provider.example'], $this->resolver($records));

        $this->assertFalse($sources->allows('192.0.2.1'));
    }
}
