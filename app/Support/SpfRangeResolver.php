<?php

namespace App\Support;

use Closure;

/**
 * Expands a domain's SPF record (following include: and redirect=) into the
 * IP addresses and CIDR ranges it authorises.
 */
class SpfRangeResolver
{
    private const int MAX_DEPTH = 10;

    /**
     * @param  (Closure(string): list<string>)|null  $lookup  returns the TXT records of a domain
     */
    public function __construct(private readonly ?Closure $lookup = null) {}

    /**
     * @return list<string>
     */
    public function resolve(string $domain): array
    {
        $ranges = [];
        $visited = [];

        $this->collect(strtolower($domain), $ranges, $visited, 0);

        return array_values(array_unique($ranges));
    }

    /**
     * @param  list<string>  $ranges
     * @param  array<string, true>  $visited
     */
    private function collect(string $domain, array &$ranges, array &$visited, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || isset($visited[$domain])) {
            return;
        }

        $visited[$domain] = true;

        foreach ($this->txtRecords($domain) as $record) {
            if (! preg_match('/^v=spf1(\s|$)/i', $record)) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($record)) as $term) {
                $term = strtolower($term);

                if (preg_match('/^\+?ip[46]:(.+)$/', $term, $matches)) {
                    $ranges[] = $matches[1];
                } elseif (preg_match('/^\+?include:(.+)$/', $term, $matches) || preg_match('/^redirect=(.+)$/', $term, $matches)) {
                    $this->collect($matches[1], $ranges, $visited, $depth + 1);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function txtRecords(string $domain): array
    {
        if ($this->lookup !== null) {
            return ($this->lookup)($domain);
        }

        $records = @dns_get_record($domain, DNS_TXT) ?: [];

        return array_values(array_map(fn (array $record): string => (string) ($record['txt'] ?? ''), $records));
    }
}
