<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Organisation;
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
     * domains — stat tiles, daily trend, why-DMARC-fails breakdown and top
     * sending sources, for sharing with people who don't have an app login.
     * Dompdf renders plain HTML/CSS (no Tailwind, no Chart.js), so the trend
     * is a compact table rather than a chart.
     */
    public function __invoke(Request $request, Organisation $organisation, DmarcMetricsService $service): Response
    {
        abort_unless($request->user()->canAccessOrganisation($organisation->id), 404);

        $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->string('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        $domainIds = Domain::where('organisation_id', $organisation->id)->pluck('id')->all();

        $data = [
            'organisation' => $organisation,
            'from' => $from,
            'to' => $to,
            'domainCount' => count($domainIds),
            'summary' => $service->summary(null, $from, $to, $organisation->id),
            'trend' => $service->trend(null, $from, $to, $organisation->id),
            'failureBreakdown' => $service->failureBreakdown(null, $from, $to, $organisation->id),
            'topSources' => $service->groupedSourceBreakdown(null, $from, $to, $organisation->id)->take(15),
            'generatedAt' => now(),
        ];

        $pdf = Pdf::loadView('pdf.organisation-report', $data)->setPaper('a4');

        $filename = Str::slug($organisation->name).'-dmarc-report-'.$to->toDateString().'.pdf';

        return $pdf->stream($filename);
    }
}
