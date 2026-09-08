<?php

namespace App\Services\Analytics;

use App\Models\AggregateReportRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DmarcMetricsService
{
    /**
     * Daily DMARC/SPF/DKIM pass-rate trend across the given window.
     *
     * @return Collection<int, array{date: string, total: int, dmarc_pass_pct: float, spf_pass_pct: float, dkim_pass_pct: float}>
     */
    public function trend(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null): Collection
    {
        $rows = $this->baseQuery($domainId, $from, $to, $organisationId)
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
    public function sourceBreakdown(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null): Collection
    {
        $rows = $this->baseQuery($domainId, $from, $to, $organisationId)
            ->selectRaw('aggregate_report_records.source_ip')
            ->selectRaw('MAX(aggregate_report_records.ptr_hostname) as ptr_hostname')
            ->selectRaw('MAX(aggregate_report_records.asn_org) as asn_org')
            ->selectRaw('SUM(aggregate_report_records.count) as total')
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' OR aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dmarc_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.spf_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as spf_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as dkim_pass")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.disposition != 'none' AND aggregate_report_records.disposition != 'pass' THEN aggregate_report_records.count ELSE 0 END) as enforced")
            ->groupBy('aggregate_report_records.source_ip')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row) => [
            'source_ip' => $row->source_ip,
            'ptr_hostname' => $row->ptr_hostname,
            'asn_org' => $row->asn_org,
            'total' => (int) $row->total,
            'dmarc_pass' => (int) $row->dmarc_pass,
            'dmarc_fail' => (int) $row->total - (int) $row->dmarc_pass,
            'dmarc_pass_pct' => $this->percentage($row->dmarc_pass, $row->total),
            'spf_pass_pct' => $this->percentage($row->spf_pass, $row->total),
            'dkim_pass_pct' => $this->percentage($row->dkim_pass, $row->total),
            'enforced' => (int) $row->enforced,
        ]);
    }

    /**
     * Overall summary totals for the given window, for stat tiles.
     *
     * @return array{total: int, dmarc_pass_pct: float, spf_pass_pct: float, dkim_pass_pct: float, distinct_sources: int}
     */
    public function summary(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null): array
    {
        $row = $this->baseQuery($domainId, $from, $to, $organisationId)
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

    private function baseQuery(?int $domainId, CarbonInterface $from, CarbonInterface $to, ?int $organisationId = null)
    {
        $query = AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->whereBetween('aggregate_reports.date_range_begin', [$from, $to]);

        if ($domainId !== null) {
            $query->where('aggregate_reports.domain_id', $domainId);
        }

        if ($organisationId !== null) {
            $query->join('domains', 'domains.id', '=', 'aggregate_reports.domain_id')
                ->where('domains.organisation_id', $organisationId);
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
