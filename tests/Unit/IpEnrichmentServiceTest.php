<?php

namespace Tests\Unit;

use App\Models\IpEnrichmentCache;
use App\Services\Enrichment\IpEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_and_caches_a_ptr_hostname(): void
    {
        $service = new IpEnrichmentService(fn (string $ip) => 'mail.example.com');

        $result = $service->enrich('203.0.113.10');

        $this->assertEquals('mail.example.com', $result['ptr_hostname']);
        $this->assertNull($result['asn']);

        $this->assertDatabaseHas('ip_enrichment_cache', [
            'ip' => '203.0.113.10',
            'ptr_hostname' => 'mail.example.com',
            'lookup_failed' => false,
        ]);
    }

    public function test_a_failed_ptr_lookup_is_cached_as_failed(): void
    {
        // gethostbyaddr() returns the IP itself, unchanged, when resolution fails.
        $service = new IpEnrichmentService(fn (string $ip) => $ip);

        $result = $service->enrich('203.0.113.20');

        $this->assertNull($result['ptr_hostname']);
        $this->assertDatabaseHas('ip_enrichment_cache', [
            'ip' => '203.0.113.20',
            'ptr_hostname' => null,
            'lookup_failed' => true,
        ]);
    }

    public function test_it_reuses_a_fresh_cache_entry_without_calling_the_resolver_again(): void
    {
        $calls = 0;
        $service = new IpEnrichmentService(function (string $ip) use (&$calls) {
            $calls++;

            return 'mail.example.com';
        });

        $service->enrich('203.0.113.30');
        $service->enrich('203.0.113.30');

        $this->assertEquals(1, $calls);
    }

    public function test_it_refreshes_a_stale_cache_entry(): void
    {
        IpEnrichmentCache::create([
            'ip' => '203.0.113.40',
            'ptr_hostname' => 'old.example.com',
            'looked_up_at' => now()->subDays(60),
            'lookup_failed' => false,
        ]);

        $service = new IpEnrichmentService(fn (string $ip) => 'fresh.example.com');

        $result = $service->enrich('203.0.113.40');

        $this->assertEquals('fresh.example.com', $result['ptr_hostname']);
    }

    public function test_asn_lookup_is_skipped_gracefully_when_no_mmdb_file_is_configured(): void
    {
        config(['geoip.mmdb_path' => '/nonexistent/path/GeoLite2-ASN.mmdb']);

        $service = new IpEnrichmentService(fn (string $ip) => 'mail.example.com');

        $result = $service->enrich('203.0.113.50');

        $this->assertNull($result['asn']);
        $this->assertNull($result['asn_org']);
    }
}
