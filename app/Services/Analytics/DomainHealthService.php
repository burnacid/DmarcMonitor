<?php

namespace App\Services\Analytics;

use App\Models\AggregateReport;
use App\Models\AlertEvent;
use App\Models\Domain;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Per-domain health for pages that show many domains at once (the client
 * overview and the domains list): what needs attention, and how close the
 * domain is to moving to the next, stricter DMARC policy.
 */
class DomainHealthService
{
    /** Pass rate at or above which a domain counts as healthy. */
    public const float PASS_GOOD = 95.0;

    /** Pass rate below which a domain counts as critical. */
    public const float PASS_CRITICAL = 80.0;

    /** Messages needed before a low pass rate is flagged, so a handful of forwarded mails isn't. */
    public const int MIN_VOLUME_FOR_PASS_RATE = 10;

    /** Days without a newly ingested report before an active domain is flagged. */
    public const int NO_REPORTS_DAYS = 7;

    /** Days a newly added domain gets before missing reports are flagged. */
    public const int NEW_DOMAIN_GRACE_DAYS = 3;

    /** Days of report history used to judge whether a domain only sends mail now and then. */
    public const int RARELY_SENDS_LOOKBACK_DAYS = 90;

    /** Days of report history needed before a domain can count as rarely sending. */
    public const int RARELY_SENDS_MIN_HISTORY_DAYS = 30;

    /** Share of observed days with reports below which a domain counts as rarely sending. */
    public const float RARELY_SENDS_MAX_DAY_SHARE = 0.25;

    /** Readiness is always judged on this many days, whatever period a page displays. */
    public const int READINESS_WINDOW_DAYS = 30;

    public const int READINESS_MIN_HISTORY_DAYS = 14;

    public const int READINESS_MIN_MESSAGES = 100;

    public const float READINESS_PASS_FOR_QUARANTINE = 98.0;

    public const float READINESS_PASS_FOR_REJECT = 99.0;

    public const array ISSUES = ['no_reports', 'dmarc_missing', 'spf_missing', 'dkim_missing', 'low_pass_rate', 'open_alerts', 'not_reporting_here', 'report_auth_missing'];

    /** Worth knowing, but not a reason for a domain to need attention. */
    public const array REMARKS = ['rarely_sends', 'no_mail_seen'];

    public function __construct(private DmarcMetricsService $metrics) {}

    public static function passStatus(float $pct): string
    {
        return match (true) {
            $pct >= self::PASS_GOOD => 'good',
            $pct >= self::PASS_CRITICAL => 'warning',
            default => 'critical',
        };
    }

    public static function issueLabel(string $issue): string
    {
        return match ($issue) {
            'no_reports' => __('No recent reports'),
            'dmarc_missing' => __('DMARC missing'),
            'spf_missing' => __('SPF missing'),
            'dkim_missing' => __('DKIM missing'),
            'low_pass_rate' => __('Low pass rate'),
            'open_alerts' => __('Open alerts'),
            'not_reporting_here' => __('Reports sent elsewhere'),
            'report_auth_missing' => __('Report authorisation missing'),
            default => $issue,
        };
    }

    public static function remarkLabel(string $remark): string
    {
        return match ($remark) {
            'rarely_sends' => __('Rarely sends mail'),
            'no_mail_seen' => __('No mail seen'),
            default => $remark,
        };
    }

    public static function remarkDescription(string $remark): string
    {
        return match ($remark) {
            'rarely_sends' => __('No reports for over :days days, which is normal for this domain.', ['days' => self::NO_REPORTS_DAYS]),
            'no_mail_seen' => __('The DMARC record sends reports here, but none have arrived yet. The domain most likely sends no mail.'),
            default => '',
        };
    }

    /**
     * One-line summary of a readiness result, e.g. "Ready for quarantine"
     * or the first check still blocking it.
     *
     * @param  array{step: string, next_policy: ?string, ready: bool, checks: list<array{label: string, passed: bool}>}  $readiness
     */
    public static function readinessHint(array $readiness): string
    {
        return match (true) {
            $readiness['step'] === 'publish' => __('Publish a DMARC record'),
            $readiness['step'] === 'enforced' => __('Enforced'),
            $readiness['ready'] && $readiness['step'] === 'raise_pct' => __('Ready for pct=100'),
            $readiness['ready'] => __('Ready for :policy', ['policy' => $readiness['next_policy']]),
            default => collect($readiness['checks'])->firstWhere('passed', false)['label'] ?? '',
        };
    }

    /**
     * @param  Collection<int, Domain>  $domains  Already limited to what the viewer may see.
     * @return Collection<int, array<string, mixed>> Keyed by domain id.
     */
    public function forDomains(Collection $domains, int $days = 30): Collection
    {
        $domainIds = $domains->pluck('id')->all();

        if ($domainIds === []) {
            return collect();
        }

        $now = Carbon::now();
        $periodSummaries = $this->metrics->summaryByDomain($domainIds, $now->copy()->subDays($days - 1)->startOfDay(), $now->copy()->endOfDay());
        $readinessSummaries = $days === self::READINESS_WINDOW_DAYS
            ? $periodSummaries
            : $this->metrics->summaryByDomain($domainIds, $now->copy()->subDays(self::READINESS_WINDOW_DAYS - 1)->startOfDay(), $now->copy()->endOfDay());

        $reportDates = AggregateReport::query()
            ->whereIn('domain_id', $domainIds)
            ->groupBy('domain_id')
            ->selectRaw('domain_id, MAX(created_at) as last_report_at, MIN(date_range_begin) as first_report_at')
            ->selectRaw('COUNT(DISTINCT CASE WHEN date_range_begin >= ? THEN DATE(date_range_begin) END) as recent_report_days', [$now->copy()->subDays(self::RARELY_SENDS_LOOKBACK_DAYS - 1)->startOfDay()])
            ->get()
            ->keyBy('domain_id');

        $openAlerts = AlertEvent::query()
            ->whereIn('domain_id', $domainIds)
            ->whereNull('resolved_at')
            ->groupBy('domain_id')
            ->selectRaw('domain_id, COUNT(*) as open_count')
            ->pluck('open_count', 'domain_id');

        return $domains->mapWithKeys(function (Domain $domain) use ($periodSummaries, $readinessSummaries, $reportDates, $openAlerts, $now) {
            $summary = $periodSummaries->get($domain->id, ['total' => 0, 'dmarc_pass' => 0, 'dmarc_pass_pct' => 0.0]);
            $dates = $reportDates->get($domain->id);
            $lastReportAt = $dates?->last_report_at ? Carbon::parse($dates->last_report_at) : null;
            $firstReportAt = $dates?->first_report_at ? Carbon::parse($dates->first_report_at) : null;

            $health = [
                'domain' => $domain,
                'total' => $summary['total'],
                'dmarc_pass' => $summary['dmarc_pass'],
                'dmarc_pass_pct' => $summary['dmarc_pass_pct'],
                'pass_status' => $summary['total'] > 0 ? self::passStatus($summary['dmarc_pass_pct']) : null,
                'last_report_at' => $lastReportAt,
                'first_report_at' => $firstReportAt,
                'open_alerts' => (int) ($openAlerts[$domain->id] ?? 0),
                'policy' => $domain->dmarcPolicy(),
                'reports_to_us' => $domain->reportsToThisTool(),
                'rarely_sends' => $this->rarelySends($firstReportAt, (int) ($dates?->recent_report_days ?? 0), $now),
            ];

            $health['issues'] = $this->issues($domain, $health, $now);
            $health['remarks'] = $this->remarks($domain, $health, $now);
            $health['readiness'] = $this->readiness(
                $domain,
                $readinessSummaries->get($domain->id, ['total' => 0, 'dmarc_pass_pct' => 0.0]),
                $firstReportAt,
                $now,
            );

            return [$domain->id => $health];
        });
    }

    /**
     * Totals across several domains' health rows, e.g. one organisation.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{domains: int, total: int, dmarc_pass_pct: ?float, pass_status: ?string, policies: array<string, int>, domains_with_issues: int, issues: int, open_alerts: int, last_report_at: ?CarbonInterface}
     */
    public function rollUp(Collection $rows): array
    {
        $total = $rows->sum('total');
        $passPct = $total > 0 ? round($rows->sum('dmarc_pass') / $total * 100, 1) : null;

        $policies = collect(['reject', 'quarantine', 'none', 'missing'])
            ->mapWithKeys(fn (string $policy) => [$policy => $rows->filter(fn (array $row) => ($row['policy'] ?? 'missing') === $policy)->count()])
            ->filter()
            ->all();

        return [
            'domains' => $rows->count(),
            'total' => $total,
            'dmarc_pass_pct' => $passPct,
            'pass_status' => $passPct !== null ? self::passStatus($passPct) : null,
            'policies' => $policies,
            'domains_with_issues' => $rows->filter(fn (array $row) => $row['issues'] !== [])->count(),
            'issues' => $rows->sum(fn (array $row) => count($row['issues'])),
            'open_alerts' => $rows->sum('open_alerts'),
            'last_report_at' => $rows->pluck('last_report_at')->filter()->max(),
        ];
    }

    /**
     * @param  array<string, mixed>  $health
     * @return list<string>
     */
    private function issues(Domain $domain, array $health, CarbonInterface $now): array
    {
        if (! $domain->is_active) {
            return [];
        }

        $issues = [];

        if ($this->reportsOverdue($domain, $health, $now) && $this->quietSpellRemark($health) === null) {
            $issues[] = 'no_reports';
        }

        if ($domain->dmarc_status === 'missing') {
            $issues[] = 'dmarc_missing';
        }

        if ($domain->spf_status === 'missing') {
            $issues[] = 'spf_missing';
        }

        if ($domain->dkim_status === 'missing') {
            $issues[] = 'dkim_missing';
        }

        if ($health['total'] >= self::MIN_VOLUME_FOR_PASS_RATE && $health['pass_status'] !== 'good') {
            $issues[] = 'low_pass_rate';
        }

        if ($health['open_alerts'] > 0) {
            $issues[] = 'open_alerts';
        }

        if ($domain->dmarc_record !== null && $health['reports_to_us'] === false) {
            $issues[] = 'not_reporting_here';
        }

        if (collect($domain->missingReportAuthorizations())->contains('in_record', true)) {
            $issues[] = 'report_auth_missing';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $health
     * @return list<string>
     */
    private function remarks(Domain $domain, array $health, CarbonInterface $now): array
    {
        if (! $domain->is_active) {
            return [];
        }

        $remark = $this->quietSpellRemark($health);

        return $remark !== null && $this->reportsOverdue($domain, $health, $now) ? [$remark] : [];
    }

    /**
     * Why missing reports are expected rather than a problem, if they are:
     * the domain has historically sent only now and then, or its DMARC
     * record points here and no report has ever arrived, so it most likely
     * sends no mail at all.
     *
     * @param  array<string, mixed>  $health
     */
    private function quietSpellRemark(array $health): ?string
    {
        return match (true) {
            $health['rarely_sends'] => 'rarely_sends',
            $health['last_report_at'] === null && $health['reports_to_us'] === true => 'no_mail_seen',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function reportsOverdue(Domain $domain, array $health, CarbonInterface $now): bool
    {
        $pastGracePeriod = $domain->created_at === null || $domain->created_at->lt($now->copy()->subDays(self::NEW_DOMAIN_GRACE_DAYS));

        return $pastGracePeriod && ($health['last_report_at'] === null || $health['last_report_at']->lt($now->copy()->subDays(self::NO_REPORTS_DAYS)));
    }

    /**
     * Whether reports have historically arrived on only a few days, so a
     * quiet spell is normal. Needs at least one report to judge from.
     */
    private function rarelySends(?CarbonInterface $firstReportAt, int $recentReportDays, CarbonInterface $now): bool
    {
        if ($firstReportAt === null) {
            return false;
        }

        $observedDays = min(self::RARELY_SENDS_LOOKBACK_DAYS, (int) floor($firstReportAt->diffInDays($now, true)));

        return $observedDays >= self::RARELY_SENDS_MIN_HISTORY_DAYS
            && $recentReportDays / $observedDays < self::RARELY_SENDS_MAX_DAY_SHARE;
    }

    /**
     * The next step on the way to p=reject and whether the domain is ready
     * for it. Steps: publish (no DMARC record yet), quarantine, raise_pct
     * (a policy applied to only part of the mail), reject, enforced.
     *
     * @param  array{total: int, dmarc_pass_pct: float}  $summary
     * @return array{step: string, next_policy: ?string, ready: bool, checks: list<array{label: string, passed: bool}>}
     */
    private function readiness(Domain $domain, array $summary, ?CarbonInterface $firstReportAt, CarbonInterface $now): array
    {
        $policy = $domain->dmarcPolicy();
        $pct = (int) ($domain->dmarcTags()['pct'] ?? 100);

        [$step, $nextPolicy] = match (true) {
            $policy === null => ['publish', 'none'],
            $policy === 'none' => ['quarantine', 'quarantine'],
            $pct < 100 => ['raise_pct', $policy],
            $policy === 'quarantine' => ['reject', 'reject'],
            default => ['enforced', null],
        };

        if (in_array($step, ['publish', 'enforced'], true)) {
            return ['step' => $step, 'next_policy' => $nextPolicy, 'ready' => true, 'checks' => []];
        }

        $requiredPass = $nextPolicy === 'reject' ? self::READINESS_PASS_FOR_REJECT : self::READINESS_PASS_FOR_QUARANTINE;
        $historyDays = $firstReportAt ? (int) floor($firstReportAt->diffInDays($now, true)) : 0;

        $checks = [
            [
                'label' => __(':days days of reports (needs :required)', ['days' => $historyDays, 'required' => self::READINESS_MIN_HISTORY_DAYS]),
                'passed' => $historyDays >= self::READINESS_MIN_HISTORY_DAYS,
            ],
            [
                'label' => __(':count messages in the last :window days (needs :required)', ['count' => number_format($summary['total']), 'window' => self::READINESS_WINDOW_DAYS, 'required' => self::READINESS_MIN_MESSAGES]),
                'passed' => $summary['total'] >= self::READINESS_MIN_MESSAGES,
            ],
            [
                'label' => __('DMARC pass rate :pct% (needs :required%)', ['pct' => $summary['dmarc_pass_pct'], 'required' => $requiredPass]),
                'passed' => $summary['total'] > 0 && $summary['dmarc_pass_pct'] >= $requiredPass,
            ],
            [
                'label' => __('SPF record published'),
                'passed' => $domain->spf_status === 'valid',
            ],
            [
                'label' => __('DKIM record found'),
                'passed' => $domain->dkim_status === 'valid',
            ],
        ];

        return [
            'step' => $step,
            'next_policy' => $nextPolicy,
            'ready' => collect($checks)->every('passed'),
            'checks' => $checks,
        ];
    }
}
