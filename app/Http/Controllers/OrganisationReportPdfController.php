<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Organisation;
use App\Models\ReportBranding;
use App\Services\Analytics\DmarcMetricsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class OrganisationReportPdfController extends Controller
{
    /**
     * A PDF summary of one organisation's DMARC posture across all its
     * domains, or just one with ?domain=<id> — stat tiles, daily trend,
     * why-DMARC-fails breakdown and top sending sources, for sharing with
     * people who don't have an app login. Dompdf renders plain HTML/CSS (no Tailwind, no Chart.js), so the trend
     * is a compact table rather than a chart.
     */
    public function __invoke(Request $request, Organisation $organisation, DmarcMetricsService $service): Response
    {
        abort_unless($request->user()->canAccessOrganisation($organisation->id), 404);

        $service = $service->hidingExpectedSources();

        $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->string('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        $domain = $request->filled('domain')
            ? Domain::where('organisation_id', $organisation->id)->findOrFail($request->integer('domain'))
            : null;
        $domainId = $domain?->id;

        $domainCount = $domain ? 1 : Domain::where('organisation_id', $organisation->id)->count();

        $data = [
            'organisation' => $organisation,
            'from' => $from,
            'to' => $to,
            'domain' => $domain,
            'domainCount' => $domainCount,
            'summary' => $service->summary($domainId, $from, $to, $organisation->id),
            'trend' => $service->trend($domainId, $from, $to, $organisation->id),
            'failureBreakdown' => $service->failureBreakdown($domainId, $from, $to, $organisation->id),
            'topSources' => $service->groupedSourceBreakdown($domainId, $from, $to, $organisation->id, withEnvelopes: false)->take(15),
            'generatedAt' => now(),
            'branding' => ReportBranding::forReports(),
        ];

        $pdf = Pdf::loadView('pdf.organisation-report', $data)->setPaper('a4');

        $filename = Str::slug($domain->fqdn ?? $organisation->name).'-dmarc-report-'.$to->toDateString().'.pdf';

        return $pdf->stream($filename);
    }
}
