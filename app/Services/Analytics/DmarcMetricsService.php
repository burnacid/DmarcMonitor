<?php

namespace App\Services\Analytics;

use App\Models\AggregateReportRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DmarcMetricsService
{
    /**
     * Label used for records with no envelope-to (RFC5321.RcptTo) recorded,
     * so their volume and pass rates still show up as their own row in the
     * envelope breakdown instead of silently vanishing from it.
     */
    private const NO_ENVELOPE_LABEL = '(no envelope-to data)';

    /**
     * Cache key holding a counter that is bumped whenever report data changes,
     * so cached dashboard figures are invalidated as soon as new reports
     * arrive or get enriched rather than only when their TTL runs out.
     */
    private const CACHE_VERSION_KEY = 'dmarc-metrics-version';

    private const DASHBOARD_CACHE_MINUTES = 10;

    /**
     * Invalidates all cached metrics; call after report data changes.
     */
    public static function flushCache(): void
    {
        Cache::add(self::CACHE_VERSION_KEY, 0);
        Cache::increment(self::CACHE_VERSION_KEY);
    }

    /**
     * Daily DMARC/SPF/DKIM pass-rate trend across the given window.
     *
     * @return Collection<int, array{date: string, total: int, dmarc_pass_pct: float, spf_pass_pct: float, dkim_pass_pct: float}>
     */
    public function trend(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null): Collection
    {
        $rows = $this->baseQuery($domainId, $from, $to, $organisationId, $allowedDomainIds)
            ->selectRaw('DATE(aggregate_reports.date_range_begin) as day')
            ->selectRaw('SUM(aggregate_report_records.count) as total')
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' OR aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dmarc_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as spf_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dkim_pass")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => $row->day,
            'total' => (int) $row->total,
            'dmarc_pass_pct' => $this->percentage($row->dmarc_pass, $row->total),
            'spf_pass_pct' => $this->percentage($row->spf_pass, $row->total),
            'dkim_pass_pct' => $this->percentage($row->dkim_pass, $row->total),
        ]);
    }

    /**
     * Sending source breakdown across the given window, ordered by volume.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sourceBreakdown(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null): Collection
    {
        $envelopeDomains = $this->envelopeDomainsBySourceIp($domainId, $from, $to, $organisationId, $allowedDomainIds);

        return $this->sourceRows($domainId, $from, $to, $organisationId, $allowedDomainIds)
            ->map(fn (array $source) => $source + [
                'envelope_domains' => $envelopeDomains->get("{$source['domain_id']}|{$source['source_ip']}", collect())->all(),
            ]);
    }

    /**
     * Volume and pass-rate aggregates per (source IP, domain) pair, ordered by volume.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function sourceRows(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null): Collection
    {
        $rows = $this->baseQuery($domainId, $from, $to, $organisationId, $allowedDomainIds)
            ->selectRaw('aggregate_report_records.source_ip')
            ->selectRaw('aggregate_reports.domain_id')
            ->selectRaw('MAX(domains.fqdn) as domain')
            ->selectRaw('MAX(aggregate_report_records.ptr_hostname) as ptr_hostname')
            ->selectRaw('MAX(aggregate_report_records.asn_org) as asn_org')
            ->selectRaw('MAX(aggregate_report_records.country) as country')
            ->selectRaw('SUM(aggregate_report_records.count) as total')
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' OR aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dmarc_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as spf_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dkim_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.disposition != 'none' AND aggregate_report_records.disposition != 'pass' THEN aggregate_report_records.count ELSE 0 END) as enforced")
            ->groupBy('aggregate_report_records.source_ip', 'aggregate_reports.domain_id')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row) => [
            'source_ip' => $row->source_ip,
            'domain_id' => (int) $row->domain_id,
            'domain' => $row->domain,
            'ptr_hostname' => $row->ptr_hostname,
            'asn_org' => $row->asn_org,
            'country' => $row->country,
            'total' => (int) $row->total,
            'dmarc_pass' => (int) $row->dmarc_pass,
            'dmarc_fail' => (int) $row->total - (int) $row->dmarc_pass,
            'spf_pass' => (int) $row->spf_pass,
            'dkim_pass' => (int) $row->dkim_pass,
            'dmarc_pass_pct' => $this->percentage($row->dmarc_pass, $row->total),
            'spf_pass_pct' => $this->percentage($row->spf_pass, $row->total),
            'dkim_pass_pct' => $this->percentage($row->dkim_pass, $row->total),
            'enforced' => (int) $row->enforced,
        ]);
    }

    /**
     * Distinct envelope-to (RFC5321.RcptTo) domains seen per source IP —
     * fetched separately from the aggregated breakdown since GROUP_CONCAT syntax
     * isn't portable across MySQL/SQLite.
     *
     * @return Collection<string, Collection<int, string>>
     */
    private function envelopeDomainsBySourceIp(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId, ?array $allowedDomainIds = null): Collection
    {
        return $this->baseQuery($domainId, $from, $to, $organisationId, $allowedDomainIds)
            ->select('aggregate_reports.domain_id', 'aggregate_report_records.source_ip', 'aggregate_report_records.envelope_to')
            ->whereNotNull('aggregate_report_records.envelope_to')
            ->distinct()
            ->get()
            ->groupBy(fn ($row) => "{$row->domain_id}|{$row->source_ip}")
            ->map(fn (Collection $rows) => $rows->pluck('envelope_to')->unique()->sort()->values());
    }

    /**
     * Volume and pass-rate aggregates per (source IP, envelope-to domain) pair,
     * used to split a grouped source's envelopes out individually instead of
     * merging them into one summed total.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function envelopeStatsBySourceIp(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId, ?array $allowedDomainIds = null, ?array $sourceIps = null): Collection
    {
        return $this->baseQuery($domainId, $from, $to, $organisationId, $allowedDomainIds)
            ->when($sourceIps !== null, fn (Builder $query) => $query->whereIn('aggregate_report_records.source_ip', $sourceIps))
            ->selectRaw('aggregate_report_records.source_ip')
            ->selectRaw('aggregate_reports.domain_id')
            ->selectRaw('aggregate_report_records.envelope_to')
            ->selectRaw('SUM(aggregate_report_records.count) as total')
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' OR aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dmarc_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as spf_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dkim_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.disposition != 'none' AND aggregate_report_records.disposition != 'pass' THEN aggregate_report_records.count ELSE 0 END) as enforced")
            ->groupBy('aggregate_report_records.source_ip', 'aggregate_reports.domain_id', 'aggregate_report_records.envelope_to')
            ->get()
            ->map(fn ($row) => [
                'source_ip' => $row->source_ip,
                'domain_id' => (int) $row->domain_id,
                'envelope_to' => $row->envelope_to ?? self::NO_ENVELOPE_LABEL,
                'total' => (int) $row->total,
                'dmarc_pass' => (int) $row->dmarc_pass,
                'spf_pass' => (int) $row->spf_pass,
                'dkim_pass' => (int) $row->dkim_pass,
                'enforced' => (int) $row->enforced,
            ]);
    }

    /**
     * Sending sources grouped by their resolved identity (ASN org, falling back to
     * PTR hostname, falling back to the bare IP) — each group carries its own
     * aggregated pass rates plus the individual IPs that make it up, so a shared
     * sender (e.g. one ASN/org sending from several IPs) reads as one row instead
     * of many near-duplicate ones.
     *
     * Without $withEnvelopes the per-envelope-to split is left empty, which
     * saves loading envelope stats for every source; attachEnvelopes() can
     * add it to just the groups that are shown.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function groupedSourceBreakdown(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null, bool $withEnvelopes = true): Collection
    {
        $groups = $this->sourceRows($domainId, $from, $to, $organisationId, $allowedDomainIds)
            ->groupBy(fn ($source) => $source['domain_id'].'|'.($source['asn_org'] ?? $source['ptr_hostname'] ?? $source['source_ip']))
            ->map(function (Collection $ips) {
                $total = $ips->sum('total');

                return [
                    'label' => $ips->first()['asn_org'] ?? $ips->first()['ptr_hostname'] ?? $ips->first()['source_ip'],
                    'domain' => $ips->first()['domain'],
                    'domain_id' => $ips->first()['domain_id'],
                    'ips' => $ips->values(),
                    'ip_count' => $ips->count(),
                    'country_by_ip' => $ips->pluck('country', 'source_ip')->all(),
                    'total' => $total,
                    'dmarc_pass_pct' => $this->percentage($ips->sum('dmarc_pass'), $total),
                    'spf_pass_pct' => $this->percentage($ips->sum('spf_pass'), $total),
                    'dkim_pass_pct' => $this->percentage($ips->sum('dkim_pass'), $total),
                    'enforced' => $ips->sum('enforced'),
                    'envelopes' => [],
                    'envelope_count' => 0,
                ];
            })
            ->values()
            ->sortByDesc('total')
            ->values();

        if (! $withEnvelopes) {
            return $groups;
        }

        return $this->withEnvelopeSplit($groups, $this->envelopeStatsBySourceIp($domainId, $from, $to, $organisationId, $allowedDomainIds));
    }

    /**
     * Fills in the per-envelope-to split of the given groups from
     * groupedSourceBreakdown(), loading envelope stats only for their IPs.
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return Collection<int, array<string, mixed>>
     */
    public function attachEnvelopes(Collection $groups, ?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null): Collection
    {
        if ($groups->isEmpty()) {
            return $groups;
        }

        // Chunked to stay under the database's bound-parameter limit when the
        // groups span many IPs.
        $envelopeStats = $groups
            ->flatMap(fn (array $group) => $group['ips']->pluck('source_ip'))
            ->unique()
            ->chunk(500)
            ->flatMap(fn (Collection $sourceIps) => $this->envelopeStatsBySourceIp($domainId, $from, $to, $organisationId, $allowedDomainIds, $sourceIps->values()->all()));

        return $this->withEnvelopeSplit($groups, $envelopeStats);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @param  Collection<int, array<string, mixed>>  $envelopeStats  Rows from envelopeStatsBySourceIp().
     * @return Collection<int, array<string, mixed>>
     */
    private function withEnvelopeSplit(Collection $groups, Collection $envelopeStats): Collection
    {
        $envelopeStats = $envelopeStats->groupBy(fn ($row) => "{$row['domain_id']}|{$row['source_ip']}");

        return $groups->map(function (array $group) use ($envelopeStats) {
            $envelopes = $group['ips']
                ->flatMap(fn ($ip) => $envelopeStats->get("{$ip['domain_id']}|{$ip['source_ip']}", collect()))
                ->groupBy('envelope_to')
                ->map(function (Collection $rows, string $domain) {
                    $envelopeTotal = $rows->sum('total');

                    return [
                        'domain' => $domain,
                        'ips' => $rows->pluck('source_ip')->unique()->sort()->values()->all(),
                        'total' => $envelopeTotal,
                        'dmarc_pass_pct' => $this->percentage($rows->sum('dmarc_pass'), $envelopeTotal),
                        'spf_pass_pct' => $this->percentage($rows->sum('spf_pass'), $envelopeTotal),
                        'dkim_pass_pct' => $this->percentage($rows->sum('dkim_pass'), $envelopeTotal),
                        'enforced' => $rows->sum('enforced'),
                    ];
                })
                ->values()
                ->sortByDesc('total')
                ->values();

            return [
                ...$group,
                'envelopes' => $envelopes->all(),
                'envelope_count' => $envelopes->count(),
            ];
        });
    }

    /**
     * The dashboard's sending-sources table (the $limit largest groups, with
     * their envelope split) and its top failing sources (picked from all
     * groups). Cached briefly per filter set, since computing them means
     * aggregating every record in the window; flushCache() invalidates it.
     *
     * @return array{groups: Collection<int, array<string, mixed>>, top_failing: Collection<int, array<string, mixed>>}
     */
    public function dashboardSources(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null, int $limit = 25): array
    {
        $key = 'dashboard-sources:'.md5(serialize([
            Cache::get(self::CACHE_VERSION_KEY, 0),
            $domainId,
            $from->toDateTimeString(),
            $to->toDateTimeString(),
            $organisationId,
            $allowedDomainIds,
            $limit,
        ]));

        return Cache::remember($key, now()->addMinutes(self::DASHBOARD_CACHE_MINUTES), function () use ($domainId, $from, $to, $organisationId, $allowedDomainIds, $limit) {
            $groups = $this->groupedSourceBreakdown($domainId, $from, $to, $organisationId, $allowedDomainIds, withEnvelopes: false);

            return [
                'groups' => $this->attachEnvelopes($groups->take($limit)->values(), $domainId, $from, $to, $organisationId, $allowedDomainIds),
                'top_failing' => $this->topFailingSources($groups),
            ];
        });
    }

    /**
     * The worst-performing sending sources from groupedSourceBreakdown(),
     * ignoring sources with too little volume to be meaningful (a single
     * message at 0% pass is noise, not a signal).
     *
     * @param  Collection<int, array<string, mixed>>  $sourceGroups
     * @return Collection<int, array<string, mixed>>
     */
    public function topFailingSources(Collection $sourceGroups, int $minVolume = 5, int $limit = 5): Collection
    {
        return $sourceGroups
            ->filter(fn (array $group) => $group['total'] >= $minVolume && $group['dmarc_pass_pct'] < 100)
            ->sortBy('dmarc_pass_pct')
            ->take($limit)
            ->values();
    }

    /**
     * Splits message volume by *why* DMARC does or doesn't pass — both
     * mechanisms aligned, only SPF broken, only DKIM broken, or both failed
     * (the only case that actually fails DMARC, since it's an OR of the two).
     * Surfaces a source that's silently relying on just one mechanism before
     * that mechanism breaks and takes DMARC down with it.
     *
     * @return array{total: int, both_pass: int, spf_fail_only: int, dkim_fail_only: int, both_fail: int}
     */
    public function failureBreakdown(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null): array
    {
        $row = $this->baseQuery($domainId, $from, $to, $organisationId, $allowedDomainIds)
            ->selectRaw('SUM(aggregate_report_records.count) as total')
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.spf_result = 'pass' AND aggregate_report_records.dkim_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as both_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.spf_result != 'pass' AND aggregate_report_records.dkim_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as spf_fail_only")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.spf_result = 'pass' AND aggregate_report_records.dkim_result != 'pass' THEN aggregate_report_records.count ELSE 0 END) as dkim_fail_only")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.spf_result != 'pass' AND aggregate_report_records.dkim_result != 'pass' THEN aggregate_report_records.count ELSE 0 END) as both_fail")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'both_pass' => (int) ($row->both_pass ?? 0),
            'spf_fail_only' => (int) ($row->spf_fail_only ?? 0),
            'dkim_fail_only' => (int) ($row->dkim_fail_only ?? 0),
            'both_fail' => (int) ($row->both_fail ?? 0),
        ];
    }

    /**
     * Overall summary totals for the given window, for stat tiles.
     *
     * $dateColumn selects whether the window is matched against the period a
     * report covers ('date_range_begin', the default) or against when it was
     * ingested ('created_at') — alert evaluation uses ingestion time, since
     * reports commonly arrive well after the period they cover and would
     * otherwise scroll out of a period-based window before ever being seen.
     *
     * @return array{total: int, dmarc_pass_pct: float, spf_pass_pct: float, dkim_pass_pct: float, distinct_sources: int}
     */
    public function summary(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null, string $dateColumn = 'date_range_begin'): array
    {
        $row = $this->baseQuery($domainId, $from, $to, $organisationId, $allowedDomainIds, $dateColumn)
            ->selectRaw('SUM(aggregate_report_records.count) as total')
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' OR aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dmarc_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as spf_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dkim_pass")
            ->selectRaw('COUNT(DISTINCT aggregate_report_records.source_ip) as distinct_sources')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'dmarc_pass_pct' => $this->percentage($row->dmarc_pass ?? 0, $row->total ?? 0),
            'spf_pass_pct' => $this->percentage($row->spf_pass ?? 0, $row->total ?? 0),
            'dkim_pass_pct' => $this->percentage($row->dkim_pass ?? 0, $row->total ?? 0),
            'distinct_sources' => (int) ($row->distinct_sources ?? 0),
        ];
    }

    /**
     * Message volume and DMARC pass counts per domain in one query, for
     * pages that show many domains side by side.
     *
     * @param  array<int, int>  $domainIds
     * @return Collection<int, array{total: int, dmarc_pass: int, dmarc_pass_pct: float}> Keyed by domain id.
     */
    public function summaryByDomain(array $domainIds, CarbonInterface $from, CarbonInterface $to): Collection
    {
        if ($domainIds === []) {
            return collect();
        }

        return $this->baseQuery(null, $from, $to, null, $domainIds)
            ->groupBy('aggregate_reports.domain_id')
            ->selectRaw('aggregate_reports.domain_id as domain_id')
            ->selectRaw('SUM(aggregate_report_records.count) as total')
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' OR aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dmarc_pass")
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->domain_id => [
                'total' => (int) $row->total,
                'dmarc_pass' => (int) $row->dmarc_pass,
                'dmarc_pass_pct' => $this->percentage($row->dmarc_pass, $row->total),
            ]]);
    }

    /**
     * Distinct source names already seen for a domain before the given
     * cutoff — the baseline used by the "new sending source" alert. Matched
     * against ingestion time (see summary()'s $dateColumn note) so
     * late-arriving reports aren't missed.
     *
     * @return Collection<int, string>
     */
    public function sourceNamesBefore(int $domainId, CarbonInterface $before, string $dateColumn = 'date_range_begin'): Collection
    {
        return AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->where('aggregate_reports.domain_id', $domainId)
            ->where("aggregate_reports.{$dateColumn}", '<', $before)
            ->distinct()
            ->selectRaw($this->sourceNameSql().' as source_name')
            ->pluck('source_name');
    }

    /**
     * Source names seen for a domain within the given window, each with the
     * distinct IPs behind it. Only enriched records count, so a source isn't
     * reported under its bare IP before its name is known; unenriched ones
     * become candidates once the enrichment job has run.
     *
     * With $asnResolved only records named by their ASN organisation count;
     * without it only those without one, skipping IPs that have since been
     * resolved to an ASN in any record.
     *
     * @return Collection<string, list<string>>
     */
    public function newSourceCandidates(int $domainId, CarbonInterface $from, CarbonInterface $to, string $dateColumn = 'date_range_begin', bool $asnResolved = true): Collection
    {
        return AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->where('aggregate_reports.domain_id', $domainId)
            ->whereBetween("aggregate_reports.{$dateColumn}", [$from, $to])
            ->whereNotNull('aggregate_report_records.enriched_at')
            ->when(
                $asnResolved,
                fn ($query) => $query->whereNotNull('aggregate_report_records.asn_org'),
                fn ($query) => $query->whereNull('aggregate_report_records.asn_org')
                    ->whereNotExists(fn ($resolved) => $resolved->selectRaw('1')
                        ->from('aggregate_report_records as resolved_records')
                        ->whereColumn('resolved_records.source_ip', 'aggregate_report_records.source_ip')
                        ->whereNotNull('resolved_records.asn_org')),
            )
            ->distinct()
            ->selectRaw($this->sourceNameSql().' as source_name')
            ->addSelect('aggregate_report_records.source_ip')
            ->get()
            ->groupBy('source_name')
            ->map(fn (Collection $rows) => $rows->pluck('source_ip')->sort()->values()->all());
    }

    /**
     * A source's display name, as groupedSourceBreakdown() labels it: the ASN
     * organisation, else the PTR hostname, else the bare IP.
     */
    private function sourceNameSql(): string
    {
        return 'COALESCE(aggregate_report_records.asn_org, aggregate_report_records.ptr_hostname, aggregate_report_records.source_ip)';
    }

    /**
     * Records joined to their report and domain, filtered to the window and
     * scope. A base query builder, so aggregate rows come back as plain
     * objects rather than hydrated (and far heavier) Eloquent models.
     */
    private function baseQuery(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null, string $dateColumn = 'date_range_begin'): Builder
    {
        $query = AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->join('domains', 'domains.id', '=', 'aggregate_reports.domain_id')
            ->whereNull('domains.deleted_at')
            ->whereBetween("aggregate_reports.{$dateColumn}", [$from, $to])
            ->toBase();

        if ($domainId !== null) {
            $query->where('aggregate_reports.domain_id', $domainId);
        }

        if ($organisationId !== null) {
            $query->where('domains.organisation_id', $organisationId);
        }

        if ($allowedDomainIds !== null) {
            $query->whereIn('aggregate_reports.domain_id', $allowedDomainIds);
        }

        return $query;
    }

    private function percentage(int|string|null $part, int|string|null $total): float
    {
        $total = (int) $total;
        $part = (int) $part;

        if ($total === 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 1);
    }
}
