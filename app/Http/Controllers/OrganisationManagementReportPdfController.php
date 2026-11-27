<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Organisation;
use App\Models\ReportBranding;
use App\Services\Analytics\OrganisationManagementReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class OrganisationManagementReportPdfController extends Controller
{
    /**
     * A one-glance PDF of one organisation's email security for management:
     * a verdict, a few KPIs against the previous period, a pass-rate chart,
     * a domain scorecard and next steps. The technical counterpart is
     * OrganisationReportPdfController. With ?domain=<id> it covers just that
     * one domain of the organisation.
     */
    public function __invoke(Request $request, Organisation $organisation, OrganisationManagementReport $report): Response
    {
        abort_unless($request->user()->canAccessOrganisation($organisation->id), 404);

        $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->string('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        $domain = $request->filled('domain')
            ? Domain::where('organisation_id', $organisation->id)->findOrFail($request->integer('domain'))
            : null;

        $branding = ReportBranding::forReports();

        $data = [
            'organisation' => $organisation,
            'from' => $from,
            'to' => $to,
            'domain' => $domain,
            'domainCount' => $domain ? 1 : Domain::where('organisation_id', $organisation->id)->count(),
            'generatedAt' => now(),
            'branding' => $branding,
            ...$report->build($organisation, $from, $to, $branding['accent'], $domain),
        ];

        $pdf = Pdf::loadView('pdf.organisation-management-report', $data)->setPaper('a4');

        $filename = Str::slug($domain->fqdn ?? $organisation->name).'-management-report-'.$to->toDateString().'.pdf';

        return $pdf->stream($filename);
    }
}
