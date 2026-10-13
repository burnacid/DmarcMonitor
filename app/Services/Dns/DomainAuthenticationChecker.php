<?php

namespace App\Services\Dns;

use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Support\DmarcRecord;
use Illuminate\Support\Facades\Log;
use Throwable;

class DomainAuthenticationChecker
{
    /** The most DNS lookups an SPF record may take to evaluate (RFC 7208 §4.6.4). */
    public const int SPF_MAX_LOOKUPS = 10;

    /**
     * Above this an SPF record risks not fitting in a 512-byte UDP DNS
     * answer (RFC 7208 §3.4), and some receivers fail to fetch it.
     */
    public const int SPF_MAX_RECOMMENDED_LENGTH = 450;

    /**
     * @var callable(string): array<int, string|list<string>>
     */
    private $txtResolver;

    /**
     * @param  (callable(string): array<int, string|list<string>>)|null  $txtResolver  Defaults to a real
     *                                                                                 dns_get_record() TXT lookup;
     *                                                                                 injectable so tests never hit real DNS.
     *                                                                                 A record is a string, or the list of
     *                                                                                 strings it was split into.
     */
    public function __construct(?callable $txtResolver = null)
    {
        $this->txtResolver = $txtResolver ?? function (string $hostname): array {
            $records = @dns_get_record($hostname, DNS_TXT) ?: [];

            return collect($records)
                ->map(fn (array $record) => $record['entries'] ?? [$record['txt'] ?? ''])
                ->filter(fn (array $strings) => implode('', $strings) !== '')
                ->values()
                ->all();
        };
    }

    /**
     * Check a domain's DMARC, SPF, and DKIM DNS records, returning the
     * column values to persist (does not save anything itself).
     *
     * @return array<string, mixed>
     */
    public function check(Domain $domain): array
    {
        $dmarc = $this->checkDmarc($domain->fqdn);

        return array_merge(
            $dmarc,
            $this->checkReportAuthorizations($domain->fqdn, $dmarc['dmarc_record']),
            $this->checkSpf($domain->fqdn),
            $this->checkDkim($domain),
            ['dns_checked_at' => now()],
        );
    }

    public function checkAndStore(Domain $domain): Domain
    {
        $domain->update($this->check($domain));

        return $domain->refresh();
    }

    /**
     * @return array{dmarc_status: string, dmarc_record: ?string}
     */
    private function checkDmarc(string $fqdn): array
    {
        $record = $this->firstMatching("_dmarc.{$fqdn}", fn (string $txt) => str_starts_with(strtolower($txt), 'v=dmarc1'));

        if ($record === null) {
            return ['dmarc_status' => 'missing', 'dmarc_record' => null];
        }

        preg_match('/p=(\w+)/i', $record, $matches);
        $policy = strtolower($matches[1] ?? '');

        $status = match (true) {
            in_array($policy, ['quarantine', 'reject'], true) => 'valid',
            $policy === 'none' => 'weak',
            default => 'missing',
        };

        return ['dmarc_status' => $status, 'dmarc_record' => $record];
    }

    /**
     * Looks up the "v=DMARC1" record each rua/ruf address on another domain
     * needs at <domain>._report._dmarc.<report domain> (RFC 7489 §7.1). This
     * app's own report address is always checked, even before the domain's
     * record lists it, so the record can be published ahead of the switch.
     *
     * @return array{dmarc_report_authorizations: list<array{report_domain: string, host: string, authorized: bool, in_record: bool}>}
     */
    private function checkReportAuthorizations(string $fqdn, ?string $dmarcRecord): array
    {
        $tags = DmarcRecord::parse($dmarcRecord);
        $addresses = collect([...DmarcRecord::addresses($tags['rua'] ?? null), ...DmarcRecord::addresses($tags['ruf'] ?? null)])
            ->mapWithKeys(fn (string $address) => [$address => true]);

        $ownAddress = strtolower(trim((string) config('dmarc.rua_address')));

        if ($ownAddress !== '' && ! $addresses->has($ownAddress)) {
            $addresses->put($ownAddress, false);
        }

        $authorizations = [];

        foreach ($addresses as $address => $inRecord) {
            $host = DmarcRecord::externalAuthorizationHost($fqdn, $address);

            if ($host === null) {
                continue;
            }

            if (isset($authorizations[$host])) {
                $authorizations[$host]['in_record'] = $authorizations[$host]['in_record'] || $inRecord;

                continue;
            }

            $authorizations[$host] = [
                'report_domain' => rtrim(substr(strrchr($address, '@'), 1), '.'),
                'host' => $host,
                'authorized' => $this->firstMatching($host, fn (string $txt) => str_starts_with(strtolower($txt), 'v=dmarc1')) !== null,
                'in_record' => $inRecord,
            ];
        }

        return ['dmarc_report_authorizations' => array_values($authorizations)];
    }

    /**
     * Both problems make receivers return a permerror, so SPF fails for every
     * message even though the record is published: a record split into
     * strings that glue two terms together when joined, and a record that
     * needs more than ten DNS lookups to evaluate (RFC 7208 §4.6.4).
     *
     * @return array{spf_status: string, spf_record: ?string, spf_lookup_count: ?int}
     */
    private function checkSpf(string $fqdn): array
    {
        $strings = $this->firstMatchingStrings($fqdn, $this->isSpfRecord(...));

        if ($strings === null) {
            return ['spf_status' => 'missing', 'spf_record' => null, 'spf_lookup_count' => null];
        }

        $record = implode('', $strings);
        $lookups = 0;
        $this->countSpfLookups($record, $lookups, 0);

        return [
            'spf_status' => match (true) {
                $this->hasBrokenSplit($strings) => 'broken_split',
                $lookups > self::SPF_MAX_LOOKUPS => 'too_many_lookups',
                default => 'valid',
            },
            'spf_record' => $record,
            'spf_lookup_count' => $lookups,
        ];
    }

    private function isSpfRecord(string $txt): bool
    {
        return (bool) preg_match('/^v=spf1(\s|$)/i', $txt);
    }

    private function spfRecord(string $hostname): ?string
    {
        return $this->firstMatching($hostname, $this->isSpfRecord(...));
    }

    /**
     * Receivers join a split record's strings without adding spaces, so a
     * string that ends mid-term followed by one that starts a new term
     * (e.g. "…include:a.test" "include:b.test") becomes one broken term.
     *
     * @param  list<string>  $strings
     */
    private function hasBrokenSplit(array $strings): bool
    {
        for ($i = 1; $i < count($strings); $i++) {
            $previousEndsMidTerm = $strings[$i - 1] !== '' && ! ctype_space(substr($strings[$i - 1], -1));
            $startsNewTerm = (bool) preg_match('/^[+\-~?]?(all|a|mx|ptr|include|exists|ip4|ip6|redirect|exp)([:\/=\s]|$)/i', $strings[$i]);

            if ($previousEndsMidTerm && $startsNewTerm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts the include, a, mx, ptr, exists and redirect terms that each
     * cost a DNS lookup, following include: and redirect= into the records
     * they point at. Stops descending once the limit is exceeded, since the
     * exact count past that point no longer matters.
     */
    private function countSpfLookups(string $record, int &$lookups, int $depth): void
    {
        $terms = array_map(
            fn (string $term) => ltrim(strtolower($term), '+-~?'),
            preg_split('/\s+/', trim($record)),
        );
        $hasAllMechanism = in_array('all', $terms, true);

        foreach ($terms as $term) {
            if (preg_match('/^(include:|redirect=)(.+)$/', $term, $matches)) {
                if ($matches[1] === 'redirect=' && $hasAllMechanism) {
                    continue;
                }

                $lookups++;

                if ($lookups > self::SPF_MAX_LOOKUPS || $depth >= self::SPF_MAX_LOOKUPS || str_contains($matches[2], '%')) {
                    continue;
                }

                $nested = $this->spfRecord($matches[2]);

                if ($nested !== null) {
                    $this->countSpfLookups($nested, $lookups, $depth + 1);
                }
            } elseif (preg_match('/^(a|mx|ptr|exists)([:\/]|$)/', $term)) {
                $lookups++;
            }
        }
    }

    /**
     * Every selector seen in aggregate reports is looked up again on each check:
     * the ones whose record exists are the configured selectors, so a selector
     * that has since been removed from DNS stops being reported as configured.
     * The most recently seen configured selector is kept as the primary one.
     *
     * @return array{dkim_status: string, dkim_selector: ?string, dkim_record: ?string, dkim_selectors: list<array{selector: string, record: string}>}
     */
    private function checkDkim(Domain $domain): array
    {
        $selectors = AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->where('aggregate_reports.domain_id', $domain->id)
            ->where('aggregate_report_records.dkim_domain', $domain->fqdn)
            ->whereNotNull('aggregate_report_records.dkim_selector')
            ->groupBy('aggregate_report_records.dkim_selector')
            ->orderByRaw('MAX(aggregate_reports.date_range_end) DESC')
            ->pluck('aggregate_report_records.dkim_selector');

        if ($selectors->isEmpty()) {
            return ['dkim_status' => 'unknown', 'dkim_selector' => null, 'dkim_record' => null, 'dkim_selectors' => []];
        }

        $configured = [];

        foreach ($selectors as $selector) {
            $record = $this->firstMatching(
                "{$selector}._domainkey.{$domain->fqdn}",
                fn (string $txt) => str_contains(strtolower($txt), 'v=dkim1') || str_contains($txt, 'p='),
            );

            if ($record !== null) {
                $configured[] = ['selector' => $selector, 'record' => $record];
            }
        }

        if ($configured === []) {
            return ['dkim_status' => 'missing', 'dkim_selector' => $selectors->first(), 'dkim_record' => null, 'dkim_selectors' => []];
        }

        return [
            'dkim_status' => 'valid',
            'dkim_selector' => $configured[0]['selector'],
            'dkim_record' => $configured[0]['record'],
            'dkim_selectors' => $configured,
        ];
    }

    /**
     * @param  callable(string): bool  $matches
     */
    private function firstMatching(string $hostname, callable $matches): ?string
    {
        $strings = $this->firstMatchingStrings($hostname, $matches);

        return $strings !== null ? implode('', $strings) : null;
    }

    /**
     * Like firstMatching(), but returns the record as the separate strings
     * it was published as (a TXT record over 255 characters is split).
     *
     * @param  callable(string): bool  $matches
     * @return list<string>|null
     */
    private function firstMatchingStrings(string $hostname, callable $matches): ?array
    {
        try {
            $records = ($this->txtResolver)($hostname);
        } catch (Throwable $e) {
            Log::warning("DNS TXT lookup failed for [{$hostname}]: {$e->getMessage()}");

            return null;
        }

        foreach ($records as $record) {
            $strings = array_values((array) $record);

            if ($matches(implode('', $strings))) {
                return $strings;
            }
        }

        return null;
    }
}
