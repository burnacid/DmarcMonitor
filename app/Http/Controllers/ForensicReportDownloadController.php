<?php

namespace App\Http\Controllers;

use App\Models\ForensicReport;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ForensicReportDownloadController extends Controller
{
    /**
     * Stream the raw email behind a forensic report, unmodified.
     */
    public function __invoke(ForensicReport $forensicReport): StreamedResponse
    {
        if (! $forensicReport->raw_message_path || ! Storage::disk('local')->exists($forensicReport->raw_message_path)) {
            abort(404);
        }

        return response()->streamDownload(
            fn () => print (Storage::disk('local')->get($forensicReport->raw_message_path)),
            "forensic-report-{$forensicReport->id}.eml",
            ['Content-Type' => 'message/rfc822'],
        );
    }
}
