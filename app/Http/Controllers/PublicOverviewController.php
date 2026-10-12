<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Organisation;
use App\Models\ReportBranding;
use App\Services\Analytics\DomainHealthService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only overview of every organisation for wall screens. Reachable
 * without logging in, but only from allowlisted IPs (see EnsureIpIsAllowed).
 * A plain page rather than Livewire so no update endpoint bypasses that check.
 */
class PublicOverviewController extends Controller
{
    public function __invoke(Request $request, DomainHealthService $service): View
    {
        $days = in_array($request->integer('days'), [7, 30, 90], true) ? $request->integer('days') : 30;

        $health = $service->forDomains(Domain::query()->orderBy('fqdn')->get(), $days);

        return view('public.overview', [
            'days' => $days,
            'totals' => $service->rollUp($health->values()),
            'groups' => $service->organisationGroups($health, Organisation::query()->orderBy('name')->get(), includeUnassigned: true),
            'logo' => ReportBranding::current()?->logoDataUri(forPdf: false),
        ]);
    }
}
