<?php

namespace App\Http\Controllers;

use App\Models\AggregateReport;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

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

        $path = Storage::disk('local')->path($report->raw_xml_path);
        $extension = strtolower(pathinfo($report->raw_xml_path, PATHINFO_EXTENSION));

        $xml = match ($extension) {
            'gz' => $this->readGzip($path),
            'zip' => $this->readZip($path),
            default => Storage::disk('local')->get($report->raw_xml_path),
        };

        return response()->streamDownload(
            fn () => print ($xml),
            "{$report->report_id}.xml",
            ['Content-Type' => 'application/xml'],
        );
    }

    private function readGzip(string $path): string
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open gzip file [{$path}].");
        }

        $contents = '';

        while (! gzeof($handle)) {
            $contents .= gzread($handle, 1024 * 1024);
        }

        gzclose($handle);

        return $contents;
    }

    private function readZip(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open zip file [{$path}].");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name !== false && str_ends_with(strtolower($name), '.xml')) {
                $contents = $zip->getFromIndex($i);
                $zip->close();

                if ($contents === false) {
                    throw new RuntimeException("Unable to read [{$name}] from zip file [{$path}].");
                }

                return $contents;
            }
        }

        $zip->close();

        throw new RuntimeException("No .xml file found in zip archive [{$path}].");
    }
}
