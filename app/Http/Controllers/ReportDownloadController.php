<?php

namespace App\Http\Controllers;

use App\Models\AggregateReport;
use App\Support\CompressedFileReader;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportDownloadController extends Controller
{
    /**
     * Stream the plain XML behind an aggregate report — decompressing it
     * first if the original attachment (and therefore the stored file) was
     * gzip- or zip-compressed, so the download is always readable XML
     * regardless of how the sender delivered it.
     */
    public function __invoke(AggregateReport $report): StreamedResponse
    {
        if (! $report->raw_xml_path || ! Storage::disk('local')->exists($report->raw_xml_path)) {
            abort(404);
        }

        $xml = CompressedFileReader::read(Storage::disk('local')->path($report->raw_xml_path));

        return response()->streamDownload(
            fn () => print ($xml),
            "{$report->report_id}.xml",
            ['Content-Type' => 'application/xml'],
        );
    }
}
