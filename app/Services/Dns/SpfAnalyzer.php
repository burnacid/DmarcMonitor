<?php

namespace App\Services\Dns;

use App\Support\SpfRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Evaluates an SPF record the way a receiver would, term by term and into
 * every include, to count its DNS lookups (RFC 7208 §4.6.4) and suggest
 * changes that bring a record over the limit back within it.
 *
 * @phpstan-type SpfNode array{
 *     term: string,
 *     qualifier: string,
 *     name: string,
 *     target: ?string,
 *     cost: int,
 *     lookups: int,
 *     children: list<array<string, mixed>>,
 *     ranges: list<string>,
 *     flattenable: bool,
 *     ignored: bool,
 *     void: bool,
 *     loop: bool,
 *     error: ?string,
 * }
 * @phpstan-type SpfSuggestion array{key: string, title: string, detail: string, risk: ?string, record: string, lookups: int, saves: int, length: int}
 */
class SpfAnalyzer
{
    /** Includes nested deeper than this are not followed. */
    private const int MAX_DEPTH = 10;

    /** At most this many lookups may return nothing (RFC 7208 §4.6.4). */
    public const int MAX_VOID_LOOKUPS = 2;

    /** An mx mechanism looks up the addresses of at most this many hosts. */
    private const int MAX_MX_HOSTS = 10;

    /** How long the editor reuses a DNS answer while the record is being edited. */
    private const int CACHE_SECONDS = 300;

    /**
     * @var callable(string, int): list<string>
     */
    private $resolver;

    /**
     * @param  (callable(string, int): list<string>)|null  $resolver  Returns the answers for a hostname and a
     *                                                                DNS_TXT, DNS_A, DNS_AAAA or DNS_MX type: TXT
     *                                                                records (joined), addresses, or MX hosts by
     *                                                                preference. Defaults to cached real DNS;
     *                                                                injectable so tests never hit real DNS.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $hostname, int $type): array => Cache::remember(
            "spf-dns:{$type}:{$hostname}",
            self::CACHE_SECONDS,
            fn (): array => $this->queryDns($hostname, $type),
        );
    }

    /**
     * @return array{lookups: int, void_lookups: int, terms: list<SpfNode>, warnings: list<string>}
     */
    public function analyze(string $record, string $domain): array
    {
        $domain = strtolower(rtrim($domain, '.'));
        $terms = $this->evaluateTerms(SpfRecord::parse($record), $domain, 0, [$domain]);
        $lookups = array_sum(array_column($terms, 'lookups'));

        if ($this->containsLoop($terms)) {
            // A receiver keeps following the loop until it runs out of lookups.
            $lookups = max($lookups, DomainAuthenticationChecker::SPF_MAX_LOOKUPS + 1);
        }

        $voidLookups = $this->countVoidLookups($terms);

        return [
            'lookups' => $lookups,
            'void_lookups' => $voidLookups,
            'terms' => $terms,
            'warnings' => $this->warnings($record, $terms, $lookups, $voidLookups),
        ];
    }

    /**
     * Replacement records that need fewer lookups, each a complete record
     * to publish. When the record is over the limit, a combined fix that
     * brings it within the limit comes first.
     *
     * @param  list<string>|null  $reportedSourceIps  Source IPs seen in the domain's aggregate reports;
     *                                                null when there is no report data to go by.
     * @return list<SpfSuggestion>
     */
    public function suggestions(string $record, string $domain, ?array $reportedSourceIps = null): array
    {
        $analysis = $this->analyze($record, $domain);
        $terms = SpfRecord::parse($record);
        $nodes = $analysis['terms'];
        $suggestions = [];

        $removals = [
            'duplicates' => [$this->duplicateIndexes($nodes), __('Remove duplicate includes'), __('These are already included elsewhere in the record, so each one only costs extra lookups.'), null],
            'ptr' => [$this->indexesWhere($nodes, fn (array $node) => $node['name'] === 'ptr'), __('Remove ptr'), __('The ptr mechanism is deprecated (RFC 7208 §5.5): it is slow, unreliable and many receivers ignore it.'), null],
            'broken' => [$this->indexesWhere($nodes, fn (array $node) => $node['name'] === 'include' && $node['void']), __('Remove broken includes'), __('These domains publish no SPF record; receivers treat an include of them as a permanent error.'), null],
        ];

        foreach ($this->unusedIncludeIndexes($nodes, $reportedSourceIps) as $index) {
            $removals['unused:'.$nodes[$index]['target']] = [
                [$index],
                __('Remove :term', ['term' => $nodes[$index]['term']]),
                __('None of the :count source IPs in this domain\'s recent aggregate reports is in its ranges.', ['count' => count($reportedSourceIps)]),
                __('A service that sends rarely, or only from new addresses, may still need it.'),
            ];
        }

        foreach ($removals as $key => [$indexes, $title, $detail, $risk]) {
            if ($indexes !== []) {
                $suggestions[$key] = $this->suggestion($key, $title, $detail, $risk, $this->withoutIndexes($terms, $indexes), $domain, $analysis['lookups']);
            }
        }

        foreach ($nodes as $index => $node) {
            if (! $this->canFlatten($node)) {
                continue;
            }

            $key = $node['name'] === 'include' ? 'flatten:'.$node['target'] : 'resolve:'.$index;
            $suggestions[$key] = $this->suggestion(
                $key,
                $node['name'] === 'include' ? __('Flatten :term', ['term' => $node['term']]) : __('Replace :term with its addresses', ['term' => $node['term']]),
                __('Lists its :count address ranges as ip4/ip6 terms instead.', ['count' => count($node['ranges'])]),
                $node['name'] === 'include'
                    ? __('The provider can change these addresses without notice; recheck them regularly or mail from new addresses fails SPF.')
                    : __('Update the record by hand whenever these servers\' addresses change.'),
                $this->withFlattened($terms, $nodes, [$index]),
                $domain,
                $analysis['lookups'],
            );
        }

        if ($analysis['lookups'] > DomainAuthenticationChecker::SPF_MAX_LOOKUPS) {
            $fit = $this->fitWithinLimit($terms, $nodes, array_unique(array_merge(...array_column($removals, 0))), $domain, $analysis['lookups']);

            if ($fit !== null) {
                $suggestions = ['fit' => $fit, ...$suggestions];
            }
        }

        return array_values(array_filter($suggestions, fn (?array $suggestion) => $suggestion !== null && $suggestion['saves'] > 0));
    }

    /**
     * Removes everything that can safely go, then flattens the terms that
     * save the most lookups per address range added until the record is
     * within the limit (or nothing is left to flatten).
     *
     * @param  list<array{qualifier: string, name: string, argument: string}>  $terms
     * @param  list<SpfNode>  $nodes
     * @param  list<int>  $removedIndexes
     * @return SpfSuggestion|null
     */
    private function fitWithinLimit(array $terms, array $nodes, array $removedIndexes, string $domain, int $lookups): ?array
    {
        $remaining = array_diff_key($nodes, array_flip($removedIndexes));
        $count = array_sum(array_column($remaining, 'lookups'));

        $candidates = array_filter($remaining, $this->canFlatten(...));
        uasort($candidates, fn (array $a, array $b) => $b['lookups'] / count($b['ranges']) <=> $a['lookups'] / count($a['ranges']));

        $flattenedIndexes = [];

        foreach ($candidates as $index => $node) {
            if ($count <= DomainAuthenticationChecker::SPF_MAX_LOOKUPS) {
                break;
            }

            $flattenedIndexes[] = $index;
            $count -= $node['lookups'];
        }

        $actions = [
            ...array_map(fn (int $index) => __('remove :term', ['term' => $nodes[$index]['term']]), $removedIndexes),
            ...array_map(fn (int $index) => __('flatten :term', ['term' => $nodes[$index]['term']]), $flattenedIndexes),
        ];

        if ($actions === []) {
            return null;
        }

        return $this->suggestion(
            'fit',
            $count <= DomainAuthenticationChecker::SPF_MAX_LOOKUPS
                ? __('Fit within :max lookups', ['max' => DomainAuthenticationChecker::SPF_MAX_LOOKUPS])
                : __('Reduce lookups as far as possible'),
            ucfirst(implode(', ', $actions)).'.',
            $flattenedIndexes !== [] ? __('Flattened addresses are a snapshot: recheck them regularly, as providers change their ranges without notice.') : null,
            $this->withFlattened($this->withoutIndexes($terms, $removedIndexes, keepIndexes: true), $nodes, $flattenedIndexes),
            $domain,
            $lookups,
        );
    }

    /**
     * @param  list<array{qualifier: string, name: string, argument: string}>  $terms
     * @return SpfSuggestion
     */
    private function suggestion(string $key, string $title, string $detail, ?string $risk, array $terms, string $domain, int $lookupsBefore): array
    {
        $record = SpfRecord::build(array_values(array_filter($terms)));
        $lookups = $this->analyze($record, $domain)['lookups'];

        return [
            'key' => $key,
            'title' => $title,
            'detail' => $detail,
            'risk' => $risk,
            'record' => $record,
            'lookups' => $lookups,
            'saves' => $lookupsBefore - $lookups,
            'length' => strlen($record),
        ];
    }

    /**
     * @param  SpfNode  $node
     */
    private function canFlatten(array $node): bool
    {
        return in_array($node['name'], ['include', 'a', 'mx'], true)
            && $node['flattenable']
            && $node['lookups'] > 0
            && $node['ranges'] !== [];
    }

    /**
     * Top-level terms repeated later in the record, and includes that
     * another top-level include already pulls in.
     *
     * @param  list<SpfNode>  $nodes
     * @return list<int>
     */
    private function duplicateIndexes(array $nodes): array
    {
        $seen = [];
        $indexes = [];

        foreach ($nodes as $index => $node) {
            if ($node['lookups'] === 0) {
                continue;
            }

            $text = strtolower(ltrim($node['term'], '+'));

            if (isset($seen[$text])) {
                $indexes[] = $index;

                continue;
            }

            $seen[$text] = true;

            if ($node['name'] !== 'include' || $node['target'] === null) {
                continue;
            }

            foreach ($nodes as $otherIndex => $other) {
                if ($otherIndex !== $index && ! in_array($otherIndex, $indexes, true) && $other['name'] === 'include' && in_array($node['target'], $this->includedTargets($other['children']), true)) {
                    $indexes[] = $index;

                    break;
                }
            }
        }

        return $indexes;
    }

    /**
     * @param  list<SpfNode>  $nodes
     * @return list<string>
     */
    private function includedTargets(array $nodes): array
    {
        $targets = [];

        foreach ($nodes as $node) {
            if (in_array($node['name'], ['include', 'redirect'], true) && $node['target'] !== null) {
                $targets[] = $node['target'];
            }

            array_push($targets, ...$this->includedTargets($node['children']));
        }

        return $targets;
    }

    /**
     * @param  list<SpfNode>  $nodes
     * @param  list<string>|null  $reportedSourceIps
     * @return list<int>
     */
    private function unusedIncludeIndexes(array $nodes, ?array $reportedSourceIps): array
    {
        if ($reportedSourceIps === null || $reportedSourceIps === []) {
            return [];
        }

        return $this->indexesWhere($nodes, function (array $node) use ($reportedSourceIps): bool {
            if ($node['name'] !== 'include' || ! $node['flattenable'] || $node['ranges'] === []) {
                return false;
            }

            foreach ($reportedSourceIps as $ip) {
                if (IpUtils::checkIp($ip, $node['ranges'])) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @param  list<SpfNode>  $nodes
     * @param  callable(SpfNode): bool  $matches
     * @return list<int>
     */
    private function indexesWhere(array $nodes, callable $matches): array
    {
        return array_keys(array_filter($nodes, $matches));
    }

    /**
     * @param  list<array{qualifier: string, name: string, argument: string}>  $terms
     * @param  list<int>  $indexes
     * @return list<array{qualifier: string, name: string, argument: string}|null>
     */
    private function withoutIndexes(array $terms, array $indexes, bool $keepIndexes = false): array
    {
        foreach ($indexes as $index) {
            $terms[$index] = null;
        }

        return $keepIndexes ? $terms : array_values(array_filter($terms));
    }

    /**
     * Replaces each term at the given indexes with the ip4/ip6 terms of the
     * address ranges it currently resolves to.
     *
     * @param  list<array{qualifier: string, name: string, argument: string}|null>  $terms
     * @param  list<SpfNode>  $nodes
     * @param  list<int>  $indexes
     * @return list<array{qualifier: string, name: string, argument: string}>
     */
    private function withFlattened(array $terms, array $nodes, array $indexes): array
    {
        $result = [];

        foreach ($terms as $index => $term) {
            if ($term === null) {
                continue;
            }

            if (! in_array($index, $indexes, true)) {
                $result[] = $term;

                continue;
            }

            foreach ($nodes[$index]['ranges'] as $range) {
                $result[] = ['qualifier' => $term['qualifier'], 'name' => str_contains($range, ':') ? 'ip6' : 'ip4', 'argument' => ':'.$range];
            }
        }

        return $result;
    }

    /**
     * @param  list<array{qualifier: string, name: string, argument: string}>  $terms
     * @param  list<string>  $path  The domains being evaluated, to stop include loops.
     * @return list<SpfNode>
     */
    private function evaluateTerms(array $terms, string $domain, int $depth, array $path): array
    {
        $hasAll = in_array('all', array_column($terms, 'name'), true);

        return array_map(fn (array $term) => $this->evaluateTerm($term, $domain, $depth, $path, $hasAll), $terms);
    }

    /**
     * @param  array{qualifier: string, name: string, argument: string}  $term
     * @param  list<string>  $path
     * @return SpfNode
     */
    private function evaluateTerm(array $term, string $domain, int $depth, array $path, bool $hasAll): array
    {
        $node = [
            'term' => SpfRecord::termToString($term),
            'qualifier' => $term['qualifier'],
            'name' => $term['name'],
            'target' => SpfRecord::domainSpec($term),
            'cost' => in_array($term['name'], SpfRecord::LOOKUP_TERMS, true) ? 1 : 0,
            'lookups' => 0,
            'children' => [],
            'ranges' => [],
            'flattenable' => true,
            'ignored' => false,
            'void' => false,
            'loop' => false,
            'error' => null,
        ];

        $node = match ($term['name']) {
            'ip4', 'ip6' => $this->evaluateIp($node, $term),
            'include', 'redirect' => $this->evaluateInclude($node, $depth, $path, $hasAll),
            'a', 'mx' => $this->evaluateAddresses($node, $term, $domain),
            'ptr', 'exists' => [...$node, 'flattenable' => false],
            'all', 'exp' => $node,
            default => [...$node, 'error' => SpfRecord::isModifier($term) ? null : __('Unknown mechanism; receivers treat the record as a permanent error.')],
        };

        if ($node['ignored']) {
            $node['cost'] = 0;
        }

        $node['lookups'] = $node['cost'] + array_sum(array_column($node['children'], 'lookups'));

        return $node;
    }

    /**
     * @param  SpfNode  $node
     * @param  array{qualifier: string, name: string, argument: string}  $term
     * @return SpfNode
     */
    private function evaluateIp(array $node, array $term): array
    {
        $range = ltrim($term['argument'], ':');
        [$address] = explode('/', $range, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return [...$node, 'flattenable' => false, 'error' => __('Not a valid IP address or range.')];
        }

        return [...$node, 'ranges' => [$range]];
    }

    /**
     * @param  SpfNode  $node
     * @param  list<string>  $path
     * @return SpfNode
     */
    private function evaluateInclude(array $node, int $depth, array $path, bool $hasAll): array
    {
        $target = $node['target'];

        if ($node['name'] === 'redirect' && $hasAll) {
            return [...$node, 'ignored' => true];
        }

        $error = match (true) {
            $target === null => __('Has no domain.'),
            str_contains($target, '%') => __('Uses a macro, so it can\'t be evaluated in advance.'),
            in_array($target, $path, true) => __('Includes itself in a loop.'),
            $depth >= self::MAX_DEPTH => __('Nested too deeply to follow.'),
            default => null,
        };

        if ($error !== null) {
            return [...$node, 'flattenable' => false, 'loop' => in_array($target, $path, true) || $depth >= self::MAX_DEPTH, 'error' => $error];
        }

        $record = collect($this->lookup($target, DNS_TXT))->first(fn (string $txt) => SpfRecord::isSpfRecord($txt));

        if ($record === null) {
            return [...$node, 'flattenable' => false, 'void' => true, 'error' => __('No SPF record found at :domain.', ['domain' => $target])];
        }

        $children = $this->evaluateTerms(SpfRecord::parse($record), $target, $depth + 1, [...$path, $target]);
        $ranges = [];
        $flattenable = true;

        foreach ($children as $child) {
            if ($child['ignored'] || in_array($child['name'], ['all', 'exp'], true)) {
                continue;
            }

            $flattenable = $flattenable && $child['flattenable'] && $child['qualifier'] === '+';
            array_push($ranges, ...$child['ranges']);
        }

        return [...$node, 'children' => $children, 'ranges' => array_values(array_unique($ranges)), 'flattenable' => $flattenable];
    }

    /**
     * @param  SpfNode  $node
     * @param  array{qualifier: string, name: string, argument: string}  $term
     * @return SpfNode
     */
    private function evaluateAddresses(array $node, array $term, string $domain): array
    {
        $target = $node['target'] ?? $domain;

        if (str_contains($target, '%')) {
            return [...$node, 'flattenable' => false, 'error' => __('Uses a macro, so it can\'t be evaluated in advance.')];
        }

        $hosts = $node['name'] === 'mx' ? array_slice($this->lookup($target, DNS_MX), 0, self::MAX_MX_HOSTS) : [$target];
        [$ipv4Length, $ipv6Length] = SpfRecord::cidrLengths($term);
        $ranges = [];

        foreach ($hosts as $host) {
            foreach ($this->lookup($host, DNS_A) as $address) {
                $ranges[] = $ipv4Length !== null && $ipv4Length !== 32 ? "{$address}/{$ipv4Length}" : $address;
            }

            foreach ($this->lookup($host, DNS_AAAA) as $address) {
                $ranges[] = $ipv6Length !== null && $ipv6Length !== 128 ? "{$address}/{$ipv6Length}" : $address;
            }
        }

        if ($ranges === []) {
            return [...$node, 'flattenable' => false, 'void' => true, 'error' => __('No addresses found for :domain.', ['domain' => $target])];
        }

        return [...$node, 'target' => $target, 'ranges' => array_values(array_unique($ranges))];
    }

    /**
     * @param  list<SpfNode>  $nodes
     */
    private function containsLoop(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node['loop'] || $this->containsLoop($node['children'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<SpfNode>  $nodes
     */
    private function countVoidLookups(array $nodes): int
    {
        return array_sum(array_map(fn (array $node) => ($node['void'] ? 1 : 0) + $this->countVoidLookups($node['children']), $nodes));
    }

    /**
     * @param  list<SpfNode>  $terms
     * @return list<string>
     */
    private function warnings(string $record, array $terms, int $lookups, int $voidLookups): array
    {
        $names = array_column($terms, 'name');
        $all = collect($terms)->firstWhere('name', 'all');
        $warnings = [];

        if (! SpfRecord::isSpfRecord($record)) {
            $warnings[] = __('The record must start with "v=spf1".');
        }

        if ($lookups > DomainAuthenticationChecker::SPF_MAX_LOOKUPS) {
            $warnings[] = __('Needs :count DNS lookups; receivers stop at :max and treat SPF as failed.', ['count' => $lookups, 'max' => DomainAuthenticationChecker::SPF_MAX_LOOKUPS]);
        }

        if ($voidLookups > self::MAX_VOID_LOOKUPS) {
            $warnings[] = __(':count lookups return nothing; receivers may fail SPF after :max.', ['count' => $voidLookups, 'max' => self::MAX_VOID_LOOKUPS]);
        }

        if ($all !== null && $all['qualifier'] === '+') {
            $warnings[] = __('"+all" authorises every server on the internet to send as this domain.');
        } elseif ($all !== null && $all['qualifier'] === '?') {
            $warnings[] = __('"?all" gives unlisted senders a neutral result, which protects nothing.');
        } elseif ($all === null && ! in_array('redirect', $names, true)) {
            $warnings[] = __('No "all" at the end: unlisted senders get a neutral result. End with "~all" or "-all".');
        }

        if (strlen($record) > DomainAuthenticationChecker::SPF_MAX_RECOMMENDED_LENGTH) {
            $warnings[] = __('The record is :length characters long. Above :max the DNS answer may not fit in a single UDP packet.', ['length' => strlen($record), 'max' => DomainAuthenticationChecker::SPF_MAX_RECOMMENDED_LENGTH]);
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function lookup(string $hostname, int $type): array
    {
        try {
            return ($this->resolver)($hostname, $type);
        } catch (Throwable $e) {
            Log::warning("DNS lookup failed for [{$hostname}]: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function queryDns(string $hostname, int $type): array
    {
        $records = @dns_get_record($hostname, $type) ?: [];

        if ($type === DNS_MX) {
            usort($records, fn (array $a, array $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        }

        return array_values(array_filter(array_map(fn (array $record): string => match ($type) {
            DNS_TXT => implode('', $record['entries'] ?? [$record['txt'] ?? '']),
            DNS_A => (string) ($record['ip'] ?? ''),
            DNS_AAAA => (string) ($record['ipv6'] ?? ''),
            DNS_MX => strtolower((string) ($record['target'] ?? '')),
            default => '',
        }, $records)));
    }
}
