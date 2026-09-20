<?php

namespace App\Services\Analytics;

use App\Models\AggregateReportRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DmarcMetricsService
{
    /**
     * Label used for records with no envelope-to (RFC5321.RcptTo) recorded,
     * so their volume and pass rates still show up as their own row in the
     * envelope breakdown instead of silently vanishing from it.
     */
    private const NO_ENVELOPE_LABEL = '(no envelope-to data)';

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

        $envelopeDomains = $this->envelopeDomainsBySourceIp($domainId, $from, $to, $organisationId, $allowedDomainIds);

        return $rows->map(fn ($row) => [
            'source_ip' => $row->source_ip,
            'domain_id' => $row->domain_id,
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
            'envelope_domains' => $envelopeDomains->get("{$row->domain_id}|{$row->source_ip}", collect())->all(),
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
    private function envelopeStatsBySourceIp(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId, ?array $allowedDomainIds = null): Collection
    {
        return $this->baseQuery($domainId, $from, $to, $organisationId, $allowedDomainIds)
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
                'domain_id' => $row->domain_id,
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
     * @return Collection<int, array<string, mixed>>
     */
    public function groupedSourceBreakdown(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null): Collection
    {
        $sources = $this->sourceBreakdown($domainId, $from, $to, $organisationId, $allowedDomainIds);
        $envelopeStats = $this->envelopeStatsBySourceIp($domainId, $from, $to, $organisationId, $allowedDomainIds)
            ->groupBy(fn ($row) => "{$row['domain_id']}|{$row['source_ip']}");

        return $sources
            ->groupBy(fn ($source) => $source['domain_id'].'|'.($source['asn_org'] ?? $source['ptr_hostname'] ?? $source['source_ip']))
            ->map(function (Collection $ips) use ($envelopeStats) {
                $label = $ips->first()['asn_org'] ?? $ips->first()['ptr_hostname'] ?? $ips->first()['source_ip'];
                $total = $ips->sum('total');
                $dmarcPass = $ips->sum('dmarc_pass');
                $spfPass = $ips->sum('spf_pass');
                $dkimPass = $ips->sum('dkim_pass');
                $enforced = $ips->sum('enforced');

                $envelopes = $ips
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
                    'label' => $label,
                    'domain' => $ips->first()['domain'],
                    'domain_id' => $ips->first()['domain_id'],
                    'ips' => $ips->values(),
                    'ip_count' => $ips->count(),
                    'country_by_ip' => $ips->pluck('country', 'source_ip')->all(),
                    'total' => $total,
                    'dmarc_pass_pct' => $this->percentage($dmarcPass, $total),
                    'spf_pass_pct' => $this->percentage($spfPass, $total),
                    'dkim_pass_pct' => $this->percentage($dkimPass, $total),
                    'enforced' => $enforced,
                    'envelopes' => $envelopes->all(),
                    'envelope_count' => $envelopes->count(),
                ];
            })
            ->values()
            ->sortByDesc('total')
            ->values();
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
     * Distinct source IPs already seen for a domain before the given cutoff —
     * the baseline used by the "new sending source" alert to detect newly
     * appearing IPs. Matched against ingestion time (see summary()'s
     * $dateColumn note) so late-arriving reports aren't missed.
     *
     * @return Collection<int, string>
     */
    public function sourceIpsBefore(int $domainId, CarbonInterface $before, string $dateColumn = 'date_range_begin'): Collection
    {
        return AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->where('aggregate_reports.domain_id', $domainId)
            ->where("aggregate_reports.{$dateColumn}", '<', $before)
            ->distinct()
            ->pluck('aggregate_report_records.source_ip');
    }

    /**
     * Distinct source IPs seen for a domain within the given window. Matched
     * against ingestion time (see summary()'s $dateColumn note) so
     * late-arriving reports aren't missed.
     *
     * @return Collection<int, string>
     */
    public function sourceIpsBetween(int $domainId, CarbonInterface $from, CarbonInterface $to, string $dateColumn = 'date_range_begin'): Collection
    {
        return AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->where('aggregate_reports.domain_id', $domainId)
            ->whereBetween("aggregate_reports.{$dateColumn}", [$from, $to])
            ->distinct()
            ->pluck('aggregate_report_records.source_ip');
    }

    private function baseQuery(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null, ?array $allowedDomainIds = null, string $dateColumn = 'date_range_begin')
    {
        $query = AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->join('domains', 'domains.id', '=', 'aggregate_reports.domain_id')
            ->whereNull('domains.deleted_at')
            ->whereBetween("aggregate_reports.{$dateColumn}", [$from, $to]);

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
