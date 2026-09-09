<?php

namespace App\Services\Alerts;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Services\Analytics\DmarcMetricsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class AlertEvaluationService
{
    public function __construct(
        private readonly DmarcMetricsService $metrics,
        private readonly AlertNotifier $notifier,
    ) {}

    /**
     * Evaluate every active alert rule against current data.
     */
    public function evaluateAll(): void
    {
        AlertRule::where('is_active', true)->get()->each(fn (AlertRule $rule) => $this->evaluate($rule));
    }

    public function evaluate(AlertRule $rule): void
    {
        // Unlike the other types, this one is inherently about domains that
        // aren't active yet, so it doesn't fit the "for each active domain"
        // loop below — it finds its own candidates instead.
        if ($rule->type === 'new_domain_discovered') {
            $this->checkNewDomains($rule);

            return;
        }

        $domains = $rule->domain_id !== null
            ? Domain::where('id', $rule->domain_id)->get()
            : Domain::where('is_active', true)->get();

        foreach ($domains as $domain) {
            match ($rule->type) {
                'pass_rate_drop' => $this->checkPassRate($rule, $domain),
                'spf_fail_spike' => $this->checkFailRate($rule, $domain, 'spf_pass_pct'),
                'dkim_fail_spike' => $this->checkFailRate($rule, $domain, 'dkim_pass_pct'),
                'new_source_detected' => $this->checkNewSources($rule, $domain),
            };
        }
    }

    private function checkPassRate(AlertRule $rule, Domain $domain): void
    {
        $from = $this->windowStart($rule->lookback_window);
        $summary = $this->metrics->summary($domain->id, $from, now());

        if ($summary['total'] === 0) {
            return;
        }

        $breached = $summary['dmarc_pass_pct'] < (float) $rule->threshold_percent;

        $this->applyCondition($rule, $domain, $breached, [
            'metric' => 'dmarc_pass_pct',
            'value' => $summary['dmarc_pass_pct'],
            'threshold' => (float) $rule->threshold_percent,
            'total' => $summary['total'],
            'window' => $rule->lookback_window,
        ]);
    }

    private function checkFailRate(AlertRule $rule, Domain $domain, string $passMetricKey): void
    {
        $from = $this->windowStart($rule->lookback_window);
        $summary = $this->metrics->summary($domain->id, $from, now());

        if ($summary['total'] === 0) {
            return;
        }

        $failPct = round(100 - $summary[$passMetricKey], 1);
        $breached = $failPct > (float) $rule->threshold_percent;

        $this->applyCondition($rule, $domain, $breached, [
            'metric' => $passMetricKey,
            'fail_pct' => $failPct,
            'threshold' => (float) $rule->threshold_percent,
            'total' => $summary['total'],
            'window' => $rule->lookback_window,
        ]);
    }

    private function checkNewSources(AlertRule $rule, Domain $domain): void
    {
        $windowStart = $this->windowStart($rule->lookback_window);

        $known = $this->metrics->sourceIpsBefore($domain->id, $windowStart);
        $seen = $this->metrics->sourceIpsBetween($domain->id, $windowStart, now());

        foreach ($seen->diff($known) as $ip) {
            $dedupKey = hash('sha256', "{$rule->id}:{$domain->id}:new_source:{$ip}");

            if (AlertEvent::where('dedup_key', $dedupKey)->exists()) {
                continue;
            }

            $event = AlertEvent::create([
                'alert_rule_id' => $rule->id,
                'domain_id' => $domain->id,
                'fired_at' => now(),
                'details' => ['source_ip' => $ip, 'window' => $rule->lookback_window],
                'dedup_key' => $dedupKey,
            ]);

            $this->notifier->notify($event, $rule);
        }
    }

    /**
     * Domains are auto-created (inactive) the first time a report arrives for
     * an fqdn nobody has configured yet — this surfaces those so someone
     * notices and either activates or deliberately ignores them, instead of
     * reports silently accumulating against a domain nobody is watching.
     */
    private function checkNewDomains(AlertRule $rule): void
    {
        $domains = Domain::where('is_active', false)
            ->whereHas('aggregateReports')
            ->get();

        foreach ($domains as $domain) {
            $dedupKey = hash('sha256', "{$rule->id}:new_domain:{$domain->id}");

            if (AlertEvent::where('dedup_key', $dedupKey)->exists()) {
                continue;
            }

            $event = AlertEvent::create([
                'alert_rule_id' => $rule->id,
                'domain_id' => $domain->id,
                'fired_at' => now(),
                'details' => ['domain' => $domain->fqdn],
                'dedup_key' => $dedupKey,
            ]);

            $this->notifier->notify($event, $rule);
        }
    }

    /**
     * Threshold-style rules represent a condition that holds or clears, rather
     * than a one-off occurrence: one open (unresolved) AlertEvent is kept per
     * rule/domain while the condition holds, and it's auto-resolved once the
     * metric recovers, instead of re-firing on every evaluation run.
     *
     * @param  array<string, mixed>  $details
     */
    private function applyCondition(AlertRule $rule, Domain $domain, bool $breached, array $details): void
    {
        $dedupKey = hash('sha256', "{$rule->id}:{$domain->id}:{$rule->type}");
        $openEvent = AlertEvent::where('dedup_key', $dedupKey)->whereNull('resolved_at')->first();

        if ($breached) {
            if ($openEvent) {
                return;
            }

            $event = AlertEvent::create([
                'alert_rule_id' => $rule->id,
                'domain_id' => $domain->id,
                'fired_at' => now(),
                'details' => $details,
                'dedup_key' => $dedupKey,
            ]);

            $this->notifier->notify($event, $rule);

            return;
        }

        $openEvent?->update(['resolved_at' => now()]);
    }

    private function windowStart(string $lookbackWindow): CarbonInterface
    {
        if (preg_match('/^(\d+)([hd])$/', $lookbackWindow, $matches)) {
            $amount = (int) $matches[1];

            return $matches[2] === 'h' ? now()->subHours($amount) : now()->subDays($amount);
        }

        return Carbon::now()->subHours(24);
    }
}
