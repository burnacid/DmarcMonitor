<?php

namespace App\Services\Analytics;

use App\Models\Domain;
use App\Models\Organisation;
use App\Models\ReportBranding;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Data for the management PDF: one verdict, a handful of KPIs compared with
 * the previous period of equal length, a pass-rate chart, a per-domain
 * scorecard and a short list of plain-language actions. Deliberately less
 * detail than the technical organisation report.
 */
class OrganisationManagementReport
{
    /** Most actions listed; the rest are summarised as a count. */
    public const int MAX_ACTIONS = 5;

    /** Issues that become actions, in priority order. */
    private const array ACTION_ISSUES = ['dmarc_missing', 'no_reports', 'low_pass_rate', 'spf_missing', 'dkim_missing'];

    public function __construct(
        private DmarcMetricsService $metrics,
        private DomainHealthService $health,
    ) {}

    /**
     * @return array{
     *     verdict: array{status: string, label: string, sentence: string},
     *     kpis: list<array{label: string, value: string, caption: string, delta: ?string, direction: ?string, sentiment: ?string}>,
     *     chartSvg: string,
     *     scorecard: list<array{fqdn: string, total: int, dmarc_pass_pct: ?float, pass_status: ?string, policy: ?string, partial: bool, next_step: string}>,
     *     actions: list<string>,
     *     moreActions: int,
     *     previousFrom: CarbonInterface,
     *     previousTo: CarbonInterface,
     * }
     */
    public function build(Organisation $organisation, CarbonInterface $from, CarbonInterface $to, string $accent = ReportBranding::DEFAULT_ACCENT): array
    {
        $days = (int) Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay(), true) + 1;
        $previousFrom = Carbon::parse($from)->subDays($days)->startOfDay();
        $previousTo = Carbon::parse($from)->subDay()->endOfDay();

        $domains = Domain::where('organisation_id', $organisation->id)->orderBy('fqdn')->get();
        $healthRows = $this->health->forDomains($domains);
        $rollUp = $this->health->rollUp($healthRows);

        $current = $this->metrics->summary(null, $from, $to, $organisation->id);
        $previous = $this->metrics->summary(null, $previousFrom, $previousTo, $organisation->id);
        $currentFailures = $this->metrics->failureBreakdown(null, $from, $to, $organisation->id);
        $previousFailures = $this->metrics->failureBreakdown(null, $previousFrom, $previousTo, $organisation->id);
        $periodByDomain = $this->metrics->summaryByDomain($domains->pluck('id')->all(), $from, $to);

        [$actions, $moreActions] = $this->actions($healthRows);

        return [
            'verdict' => $this->verdict($current, $healthRows, $rollUp),
            'kpis' => $this->kpis($current, $previous, $currentFailures, $previousFailures, $domains),
            'chartSvg' => $this->trendChartSvg($this->metrics->trend(null, $from, $to, $organisation->id), $accent),
            'scorecard' => $this->scorecard($healthRows, $periodByDomain),
            'actions' => $actions,
            'moreActions' => $moreActions,
            'previousFrom' => $previousFrom,
            'previousTo' => $previousTo,
        ];
    }

    /**
     * At risk when mail is failing broadly or a domain has no DMARC record at
     * all; healthy only when the pass rate is good and no domain has issues.
     *
     * @param  array{total: int, dmarc_pass_pct: float}  $current
     * @param  Collection<int, array<string, mixed>>  $healthRows
     * @param  array{domains: int, domains_with_issues: int}  $rollUp
     * @return array{status: string, label: string, sentence: string}
     */
    private function verdict(array $current, Collection $healthRows, array $rollUp): array
    {
        $passStatus = $current['total'] > 0 ? DomainHealthService::passStatus($current['dmarc_pass_pct']) : null;
        $dmarcMissing = $healthRows->contains(fn (array $row) => in_array('dmarc_missing', $row['issues'], true));

        $status = match (true) {
            $passStatus === 'critical' || $dmarcMissing => 'at_risk',
            $passStatus === 'good' && $rollUp['domains_with_issues'] === 0 => 'healthy',
            default => 'attention',
        };

        $mail = $current['total'] > 0
            ? __(':pct% of :total messages passed DMARC', ['pct' => $current['dmarc_pass_pct'], 'total' => number_format($current['total'])])
            : __('No DMARC reports were received in this period');

        $domainsLine = match (true) {
            $rollUp['domains'] === 0 => __('no domains are configured yet.'),
            $rollUp['domains_with_issues'] === 0 => __('all :count domains are in good shape.', ['count' => $rollUp['domains']]),
            default => __(':issues of :count domains need attention.', ['issues' => $rollUp['domains_with_issues'], 'count' => $rollUp['domains']]),
        };

        return [
            'status' => $status,
            'label' => match ($status) {
                'healthy' => __('Healthy'),
                'at_risk' => __('At risk'),
                default => __('Needs attention'),
            },
            'sentence' => $mail.'; '.$domainsLine,
        ];
    }

    /**
     * @param  array{total: int, dmarc_pass_pct: float}  $current
     * @param  array{total: int, dmarc_pass_pct: float}  $previous
     * @param  array{both_fail: int}  $currentFailures
     * @param  array{both_fail: int}  $previousFailures
     * @param  Collection<int, Domain>  $domains
     * @return list<array{label: string, value: string, caption: string, delta: ?string, direction: ?string, sentiment: ?string}>
     */
    private function kpis(array $current, array $previous, array $currentFailures, array $previousFailures, Collection $domains): array
    {
        $comparable = $current['total'] > 0 && $previous['total'] > 0;

        $passDelta = $comparable ? round($current['dmarc_pass_pct'] - $previous['dmarc_pass_pct'], 1) : null;
        $volumeDelta = $comparable ? round(($current['total'] - $previous['total']) / $previous['total'] * 100, 1) : null;
        $failDelta = $previous['total'] > 0 ? $currentFailures['both_fail'] - $previousFailures['both_fail'] : null;

        $protected = $domains->filter(fn (Domain $domain) => in_array($domain->dmarcPolicy(), ['quarantine', 'reject'], true)
            && (int) ($domain->dmarcTags()['pct'] ?? 100) === 100)->count();

        return [
            [
                'label' => __('DMARC pass rate'),
                'value' => $current['total'] > 0 ? $current['dmarc_pass_pct'].'%' : '—',
                'caption' => __('of all mail sent as you'),
                ...$this->delta($passDelta, fn (float $value) => $this->signed($value).' pt', higherIsBetter: true),
            ],
            [
                'label' => __('Messages'),
                'value' => number_format($current['total']),
                'caption' => __('reported by receivers'),
                ...$this->delta($volumeDelta, fn (float $value) => $this->signed($value).'%', higherIsBetter: null),
            ],
            [
                'label' => __('Unauthenticated'),
                'value' => number_format($currentFailures['both_fail']),
                'caption' => __('messages failing SPF and DKIM'),
                ...$this->delta($failDelta, fn (float $value) => $this->signed($value, 0), higherIsBetter: false),
            ],
            [
                'label' => __('Domains protected'),
                'value' => $protected.' / '.$domains->count(),
                'caption' => __('enforcing quarantine or reject'),
                'delta' => null,
                'direction' => null,
                'sentiment' => null,
            ],
        ];
    }

    /**
     * @param  callable(float): string  $format
     * @return array{delta: ?string, direction: ?string, sentiment: ?string}
     */
    private function delta(int|float|null $value, callable $format, ?bool $higherIsBetter): array
    {
        if ($value === null) {
            return ['delta' => null, 'direction' => null, 'sentiment' => null];
        }

        $direction = match (true) {
            $value > 0 => 'up',
            $value < 0 => 'down',
            default => 'flat',
        };

        $sentiment = match (true) {
            $direction === 'flat' || $higherIsBetter === null => 'neutral',
            ($direction === 'up') === $higherIsBetter => 'good',
            default => 'bad',
        };

        return ['delta' => $format((float) $value), 'direction' => $direction, 'sentiment' => $sentiment];
    }

    private function signed(float $value, int $decimals = 1): string
    {
        return ($value > 0 ? '+' : '').number_format($value, $decimals);
    }

    /**
     * Worst domains first so the eye lands on what needs work.
     *
     * @param  Collection<int, array<string, mixed>>  $healthRows
     * @param  Collection<int, array{total: int, dmarc_pass_pct: float}>  $periodByDomain
     * @return list<array{fqdn: string, total: int, dmarc_pass_pct: ?float, pass_status: ?string, policy: ?string, partial: bool, next_step: string}>
     */
    private function scorecard(Collection $healthRows, Collection $periodByDomain): array
    {
        $statusOrder = ['critical' => 0, 'warning' => 1, 'good' => 3];

        return $healthRows
            ->map(function (array $row) use ($periodByDomain) {
                /** @var Domain $domain */
                $domain = $row['domain'];
                $period = $periodByDomain->get($domain->id, ['total' => 0, 'dmarc_pass_pct' => 0.0]);

                return [
                    'fqdn' => $domain->fqdn,
                    'total' => $period['total'],
                    'dmarc_pass_pct' => $period['total'] > 0 ? $period['dmarc_pass_pct'] : null,
                    'pass_status' => $period['total'] > 0 ? DomainHealthService::passStatus($period['dmarc_pass_pct']) : null,
                    'policy' => $row['policy'],
                    'partial' => (int) ($domain->dmarcTags()['pct'] ?? 100) < 100,
                    'next_step' => DomainHealthService::readinessHint($row['readiness']),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => ($statusOrder[$a['pass_status']] ?? 2) <=> ($statusOrder[$b['pass_status']] ?? 2),
                fn (array $a, array $b) => $b['total'] <=> $a['total'],
            ])
            ->values()
            ->all();
    }

    /**
     * Plain-language next steps: problems first (in ACTION_ISSUES order),
     * then domains that are ready for a stricter policy.
     *
     * @param  Collection<int, array<string, mixed>>  $healthRows
     * @return array{0: list<string>, 1: int}
     */
    private function actions(Collection $healthRows): array
    {
        $actions = collect(self::ACTION_ISSUES)->flatMap(fn (string $issue) => $healthRows
            ->filter(fn (array $row) => in_array($issue, $row['issues'], true))
            ->map(fn (array $row) => $this->issueAction($issue, $row['domain']->fqdn))
            ->values());

        $promotions = $healthRows
            ->filter(fn (array $row) => $row['readiness']['ready'] && in_array($row['readiness']['step'], ['quarantine', 'raise_pct', 'reject'], true))
            ->map(fn (array $row) => $row['readiness']['step'] === 'raise_pct'
                ? __(':domain is ready to apply its policy to all mail (pct=100).', ['domain' => $row['domain']->fqdn])
                : __(':domain is ready to move to p=:policy.', ['domain' => $row['domain']->fqdn, 'policy' => $row['readiness']['next_policy']]))
            ->values();

        $all = $actions->concat($promotions);

        return [$all->take(self::MAX_ACTIONS)->values()->all(), max(0, $all->count() - self::MAX_ACTIONS)];
    }

    private function issueAction(string $issue, string $fqdn): string
    {
        return match ($issue) {
            'dmarc_missing' => __('Publish a DMARC record for :domain.', ['domain' => $fqdn]),
            'no_reports' => __('No recent DMARC reports for :domain — check the reporting address.', ['domain' => $fqdn]),
            'low_pass_rate' => __('Review the senders failing DMARC for :domain.', ['domain' => $fqdn]),
            'spf_missing' => __('Publish an SPF record for :domain.', ['domain' => $fqdn]),
            'dkim_missing' => __('Set up DKIM signing for :domain.', ['domain' => $fqdn]),
            default => DomainHealthService::issueLabel($issue).': '.$fqdn,
        };
    }

    /**
     * Daily DMARC pass-rate line with a dashed target line, as a standalone
     * SVG document. Dompdf can't run Chart.js, so the chart is drawn here
     * and embedded as an image. Empty string when there's nothing to plot.
     *
     * @param  Collection<int, array{date: string, total: int, dmarc_pass_pct: float}>  $trend
     */
    public function trendChartSvg(Collection $trend, string $accent = ReportBranding::DEFAULT_ACCENT): string
    {
        if ($trend->isEmpty()) {
            return '';
        }

        $width = 720;
        $height = 190;
        $left = 40;
        $right = 12;
        $top = 12;
        $bottom = 26;
        $plotWidth = $width - $left - $right;
        $plotHeight = $height - $top - $bottom;

        $minPct = (float) $trend->min('dmarc_pass_pct');
        $floor = (int) min(80, floor($minPct / 10) * 10);
        $range = max(1, 100 - $floor);

        $count = $trend->count();
        $x = fn (int $index): float => round($left + ($count === 1 ? $plotWidth / 2 : $index / ($count - 1) * $plotWidth), 1);
        $y = fn (float $pct): float => round($top + (100 - $pct) / $range * $plotHeight, 1);

        $points = $trend->values()->map(fn (array $day, int $index) => $x($index).','.$y((float) $day['dmarc_pass_pct']))->implode(' ');
        $baseline = $top + $plotHeight;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">';

        foreach (array_unique([$floor, (int) round(($floor + 100) / 2), 100]) as $tick) {
            $ty = $y($tick);
            $svg .= '<line x1="'.$left.'" y1="'.$ty.'" x2="'.($width - $right).'" y2="'.$ty.'" stroke="#eef0f4" stroke-width="1"/>';
            $svg .= '<text x="'.($left - 8).'" y="'.($ty + 3).'" font-family="Helvetica" font-size="9" fill="#9ca3af" text-anchor="end">'.$tick.'%</text>';
        }

        if ($floor < DomainHealthService::PASS_GOOD) {
            $ty = $y(DomainHealthService::PASS_GOOD);
            $svg .= '<line x1="'.$left.'" y1="'.$ty.'" x2="'.($width - $right).'" y2="'.$ty.'" stroke="#10b981" stroke-width="1" stroke-dasharray="4,3"/>';
            $svg .= '<text x="'.($width - $right).'" y="'.($ty - 4).'" font-family="Helvetica" font-size="8" fill="#059669" text-anchor="end">'.__('target').' '.(int) DomainHealthService::PASS_GOOD.'%</text>';
        }

        if ($count > 1) {
            $svg .= '<polygon points="'.$x(0).','.$baseline.' '.$points.' '.$x($count - 1).','.$baseline.'" fill="'.$accent.'" fill-opacity="0.08" stroke="none"/>';
            $svg .= '<polyline points="'.$points.'" fill="none" stroke="'.$accent.'" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
        }

        $last = $trend->last();
        $svg .= '<circle cx="'.$x($count - 1).'" cy="'.$y((float) $last['dmarc_pass_pct']).'" r="3" fill="'.$accent.'"/>';

        $labelCount = min(5, $count);
        $labelIndexes = $labelCount === 1 ? [0] : array_unique(array_map(fn (int $i) => (int) round($i * ($count - 1) / ($labelCount - 1)), range(0, $labelCount - 1)));

        foreach ($labelIndexes as $index) {
            $anchor = match (true) {
                $count === 1 => 'middle',
                $index === 0 => 'start',
                $index === $count - 1 => 'end',
                default => 'middle',
            };
            $label = Carbon::parse($trend->values()[$index]['date'])->format('j M');
            $svg .= '<text x="'.$x($index).'" y="'.($height - 8).'" font-family="Helvetica" font-size="9" fill="#9ca3af" text-anchor="'.$anchor.'">'.$label.'</text>';
        }

        return $svg.'</svg>';
    }
}
