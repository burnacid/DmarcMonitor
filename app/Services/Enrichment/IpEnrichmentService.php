<?php

namespace App\Services\Enrichment;

use App\Models\IpEnrichmentCache;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Log;
use Throwable;

class IpEnrichmentService
{
    /**
     * @var callable(string): (string|false)
     */
    private $ptrResolver;

    /**
     * @param  (callable(string): (string|false))|null  $ptrResolver  Defaults to the real gethostbyaddr();
     *                                                                 injectable so tests never hit real DNS.
     */
    public function __construct(?callable $ptrResolver = null)
    {
        $this->ptrResolver = $ptrResolver ?? gethostbyaddr(...);
    }

    /**
     * Resolve PTR hostname and ASN/org for an IP, using and refreshing the cache.
     *
     * @return array{ptr_hostname: ?string, asn: ?int, asn_org: ?string}
     */
    public function enrich(string $ip): array
    {
        $cached = IpEnrichmentCache::where('ip', $ip)->first();

        if ($cached && ! $this->isStale($cached)) {
            return [
                'ptr_hostname' => $cached->ptr_hostname,
                'asn' => $cached->asn,
                'asn_org' => $cached->asn_org,
            ];
        }

        $ptrHostname = $this->resolvePtr($ip);
        $asn = $this->resolveAsn($ip);

        $failed = $ptrHostname === null && $asn['asn'] === null;

        IpEnrichmentCache::updateOrCreate(
            ['ip' => $ip],
            [
                'ptr_hostname' => $ptrHostname,
                'asn' => $asn['asn'],
                'asn_org' => $asn['asn_org'],
                'looked_up_at' => now(),
                'lookup_failed' => $failed,
            ]
        );

        return [
            'ptr_hostname' => $ptrHostname,
            'asn' => $asn['asn'],
            'asn_org' => $asn['asn_org'],
        ];
    }

    private function isStale(IpEnrichmentCache $cached): bool
    {
        if ($cached->looked_up_at === null) {
            return true;
        }

        return $cached->looked_up_at->lt(now()->subDays((int) config('geoip.cache_days', 30)));
    }

    private function resolvePtr(string $ip): ?string
    {
        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', 3);

        try {
            $host = ($this->ptrResolver)($ip);
        } finally {
            ini_set('default_socket_timeout', $previousTimeout);
        }

        if ($host === false || $host === $ip) {
            return null;
        }

        return $host;
    }

    /**
     * @return array{asn: ?int, asn_org: ?string}
     */
    private function resolveAsn(string $ip): array
    {
        $path = config('geoip.mmdb_path');

        if (! $path || ! is_file($path)) {
            return ['asn' => null, 'asn_org' => null];
        }

        try {
            $result = (new Reader($path))->asn($ip);

            return [
                'asn' => $result->autonomousSystemNumber,
                'asn_org' => $result->autonomousSystemOrganization,
            ];
        } catch (AddressNotFoundException) {
            return ['asn' => null, 'asn_org' => null];
        } catch (Throwable $e) {
            Log::warning("GeoLite2 ASN lookup failed for {$ip}: {$e->getMessage()}");

            return ['asn' => null, 'asn_org' => null];
        }
    }
}
