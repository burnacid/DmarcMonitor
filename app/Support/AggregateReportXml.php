<?php

namespace App\Support;

use App\Models\AggregateReport;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Storage;

/**
 * The raw XML behind an aggregate report, for viewing it in the browser.
 */
class AggregateReportXml
{
    /**
     * The report's stored XML, decompressed; null when it wasn't stored or
     * the file is gone.
     */
    public static function read(AggregateReport $report): ?string
    {
        if (! $report->raw_xml_path || ! Storage::disk('local')->exists($report->raw_xml_path)) {
            return null;
        }

        return CompressedFileReader::read(Storage::disk('local')->path($report->raw_xml_path));
    }

    /**
     * The XML indented for reading, or unchanged when it doesn't parse.
     */
    public static function pretty(string $xml): string
    {
        $document = self::load($xml);

        return $document?->saveXML($document->documentElement) ?: $xml;
    }

    /**
     * Each <record> element, indented for reading, in document order: the
     * order the parser stores a report's records in.
     *
     * @return list<string>
     */
    public static function records(string $xml): array
    {
        $document = self::load($xml);

        if ($document === null) {
            return [];
        }

        $fragments = [];

        foreach ((new DOMXPath($document))->query('/*/*[local-name()="record"]') as $record) {
            $fragments[] = (string) $document->saveXML($record);
        }

        return $fragments;
    }

    /**
     * Reports come from anyone who mails the report address, so the parser
     * never fetches anything over the network.
     */
    private static function load(string $xml): ?DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;

        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }
}
