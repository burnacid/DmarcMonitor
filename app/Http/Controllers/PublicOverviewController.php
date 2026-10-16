<?php

namespace App\Http\Controllers;

use App\Models\AlertEvent;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\PublicDashboardSetting;
use App\Models\ReportBranding;
use App\Services\Analytics\DmarcMetricsService;
use App\Services\Analytics\DomainHealthService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Read-only overview for wall screens, laid out to fit one screen: problem
 * domains per organisation (all-good organisations collapsed), open alerts
 * and the worst sending sources. Inactive domains are left out entirely.
 * Open to logged-in users and, without logging in, to allowlisted IPs (see
 * EnsureIpIsAllowed); a plain page rather than Livewire so no update
 * endpoint bypasses that check.
 */
class PublicOverviewController extends Controller
{
    private const int ALERT_LIMIT = 12;

    private const int FAILING_SOURCE_LIMIT = 10;

    public function __invoke(Request $request, DomainHealthService $healthService, DmarcMetricsService $metrics): View
    {
        $days = in_array($request->integer('days'), [7, 30, 90], true) ? $request->integer('days') : 30;

        // Guests (allowlisted IPs) see everything; a logged-in user only what
        // their organisation scope allows, as on the Overview page.
        $user = $request->user();

        $domains = Domain::query()
            ->when($user, fn (Builder $query) => $query->visibleTo($user))
            ->where('is_active', true)
            ->orderBy('fqdn')
            ->get();
        $domainIds = $domains->pluck('id')->all();
        $health = $healthService->forDomains($domains, $days);

        $organisations = Organisation::query()
            ->when($user, fn (Builder $query) => $query->visibleTo($user))
            ->orderBy('name')
            ->get();

        $groups = $healthService
            ->organisationGroups($health, $organisations, includeUnassigned: ! $user?->hasOrganisationScope())
            ->filter(fn (array $group) => $group['domains']->isNotEmpty())
            ->map(fn (array $group) => $group + [
                'problem_domains' => $group['domains']->filter(fn (array $row) => $row['issues'] !== [])->values(),
            ]);

        $openAlerts = AlertEvent::query()
            ->whereNull('resolved_at')
            ->whereIn('domain_id', $domainIds);

        $sourceGroups = $metrics->groupedSourceBreakdown(
            null,
            Carbon::now()->subDays($days - 1)->startOfDay(),
            Carbon::now()->endOfDay(),
            allowedDomainIds: $domainIds,
        );

        return view('public.overview', [
            'days' => $days,
            'totals' => $healthService->rollUp($health->values()),
            'problemGroups' => $groups->filter(fn (array $group) => $group['problem_domains']->isNotEmpty())->values(),
            'healthyGroups' => $groups->filter(fn (array $group) => $group['problem_domains']->isEmpty())->sortBy('name')->values(),
            'alerts' => (clone $openAlerts)->with(['domain.organisation', 'alertRule'])->latest('fired_at')->limit(self::ALERT_LIMIT)->get(),
            'alertCount' => $openAlerts->count(),
            'failingSources' => $metrics->topFailingSources($sourceGroups, limit: self::FAILING_SOURCE_LIMIT),
            'logo' => ReportBranding::current()?->logoDataUri(forPdf: false),
            'autoScroll' => PublicDashboardSetting::autoScrolls(),
        ]);
    }
}
