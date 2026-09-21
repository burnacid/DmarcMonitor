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
        abort_unless(request()->user()->canAccessOrganisation($forensicReport->domain?->organisation_id), 404);

        if (! $forensicReport->raw_message_path || ! Storage::disk('local')->exists($forensicReport->raw_message_path)) {
            abort(404);
        }

        $isMsg = strtolower(pathinfo($forensicReport->raw_message_path, PATHINFO_EXTENSION)) === 'msg';

        return response()->streamDownload(
            fn () => print (Storage::disk('local')->get($forensicReport->raw_message_path)),
            "forensic-report-{$forensicReport->id}.".($isMsg ? 'msg' : 'eml'),
            ['Content-Type' => $isMsg ? 'application/vnd.ms-outlook' : 'message/rfc822'],
        );
    }
}
