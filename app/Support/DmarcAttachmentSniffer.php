<?php

namespace App\Support;

class DmarcAttachmentSniffer
{
    /**
     * Filename suffixes that identify a DMARC aggregate report attachment.
     */
    private const array AGGREGATE_REPORT_SUFFIXES = ['.xml', '.xml.gz', '.xml.zip', '.zip', '.gz'];

    public static function isAggregateReportFilename(string $name): bool
    {
        $name = strtolower($name);

        foreach (self::AGGREGATE_REPORT_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
