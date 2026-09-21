<?php

namespace App\Services\Smtp;

use App\Support\SpfRangeResolver;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides which client IPs may connect. Entries are plain IPs/CIDR ranges, or
 * `spf:<domain>` (e.g. `spf:spf.protection.outlook.com` for Exchange Online),
 * which is expanded from that domain's SPF record and re-resolved periodically
 * because such providers change their ranges.
 */
class AllowedSources
{
    /** @var list<string> */
    private array $staticEntries = [];

    /** @var list<string> */
    private array $spfDomains = [];

    /** @var array<string, list<string>> */
    private array $resolvedRanges = [];

    private ?int $lastRefresh = null;

    /**
     * @param  list<string>  $entries
     */
    public function __construct(
        array $entries,
        private readonly SpfRangeResolver $resolver = new SpfRangeResolver,
        private readonly int $refreshSeconds = 3600,
    ) {
        foreach ($entries as $entry) {
            $entry = trim($entry);

            if (str_starts_with(strtolower($entry), 'spf:')) {
                $this->spfDomains[] = substr($entry, 4);
            } elseif ($entry !== '') {
                $this->staticEntries[] = $entry;
            }
        }
    }

    public function allows(string $ip): bool
    {
        if ($this->lastRefresh === null || time() - $this->lastRefresh >= $this->refreshSeconds) {
            $this->refresh();
        }

        return IpUtils::checkIp($ip, [...$this->staticEntries, ...array_merge([], ...array_values($this->resolvedRanges))]);
    }

    /**
     * Re-resolve every `spf:` entry. A domain that resolves to nothing keeps its
     * previous ranges, so a DNS hiccup cannot lock out the relay.
     */
    public function refresh(): void
    {
        $this->lastRefresh = time();

        foreach ($this->spfDomains as $domain) {
            $ranges = $this->resolver->resolve($domain);

            if ($ranges === []) {
                Log::warning("SMTP listener could not resolve SPF ranges for [{$domain}]; keeping the previous ones.");

                continue;
            }

            $this->resolvedRanges[$domain] = $ranges;
        }
    }

    public function resolvedRangeCount(): int
    {
        return count(array_merge([], ...array_values($this->resolvedRanges)));
    }
}
