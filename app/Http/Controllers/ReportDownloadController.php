<?php

namespace App\Http\Controllers;

use App\Models\AggregateReport;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportDownloadController extends Controller
{
    /**
     * Stream the raw XML file behind an aggregate report.
     */
    public function __invoke(AggregateReport $report): StreamedResponse
    {
        if (! $report->raw_xml_path || ! Storage::disk('local')->exists($report->raw_xml_path)) {
            abort(404);
        }

        return Storage::disk('local')->download($report->raw_xml_path, "{$report->report_id}.xml");
    }
}
