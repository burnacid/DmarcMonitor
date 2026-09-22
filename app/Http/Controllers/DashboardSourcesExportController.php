<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\Analytics\DmarcMetricsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardSourcesExportController extends Controller
{
    /**
     * CSV of the dashboard's grouped sending-sources table for the given
     * domain/organisation/window — a distinct filter set from the reports
     * list (see ReportsExportController), so kept as its own small export
     * rather than forced into one shared query.
     */
    public function __invoke(Request $request, DmarcMetricsService $service): StreamedResponse
    {
        $user = $request->user();

        $domainId = $request->integer('domain_id') ?: null;
        $organisationId = $request->integer('organisation_id') ?: null;
        $from = Carbon::parse($request->string('from', now()->subDays(29)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->string('to', now()->toDateString()))->endOfDay();

        if ($domainId !== null && ! Domain::visibleTo($user)->whereKey($domainId)->exists()) {
            abort(404);
        }

        if ($organisationId !== null && ! $user->canAccessOrganisation($organisationId)) {
            abort(404);
        }

        $allowedDomainIds = $user->hasOrganisationScope() ? Domain::visibleTo($user)->pluck('id')->all() : null;

        return response()->streamDownload(function () use ($service, $domainId, $from, $to, $organisationId, $allowedDomainIds) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Domain', 'Source', 'IP Count', 'Volume', 'DMARC Pass %', 'SPF Pass %', 'DKIM Pass %', 'Enforced',
            ]);

            foreach ($service->groupedSourceBreakdown($domainId, $from, $to, $organisationId, $allowedDomainIds) as $group) {
                fputcsv($handle, [
                    $group['domain'],
                    $group['label'],
                    $group['ip_count'],
                    $group['total'],
                    $group['dmarc_pass_pct'],
                    $group['spf_pass_pct'],
                    $group['dkim_pass_pct'],
                    $group['enforced'],
                ]);
            }

            fclose($handle);
        }, 'dmarc-sending-sources-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
