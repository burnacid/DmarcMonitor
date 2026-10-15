<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class CompressedFileReader
{
    /**
     * Read a file's contents, transparently decompressing it first if its
     * extension indicates gzip or zip. For zip archives, an entry ending in
     * ".xml" is preferred when present; otherwise the first entry is used.
     *
     * Anyone can mail the report address, so the result is capped at
     * dmarc.max_report_bytes: a few kilobytes of crafted gzip or zip can
     * otherwise expand to gigabytes and exhaust memory.
     */
    public static function read(string $path): string
    {
        $maxBytes = (int) config('dmarc.max_report_bytes');

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'gz' => self::readGzip($path, $maxBytes),
            'zip' => self::readZip($path, $maxBytes),
            default => self::readPlain($path, $maxBytes),
        };
    }

    private static function readPlain(string $path, int $maxBytes): string
    {
        $size = filesize($path);

        if ($size === false) {
            throw new RuntimeException("Unable to read file [{$path}].");
        }

        if ($size > $maxBytes) {
            throw self::tooLarge($path, $maxBytes);
        }

        return (string) file_get_contents($path);
    }

    private static function readGzip(string $path, int $maxBytes): string
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open gzip file [{$path}].");
        }

        $contents = '';

        try {
            // Counted while reading: the size gzip stores in its trailer is
            // written by whoever built the file and can claim anything.
            while (! gzeof($handle)) {
                $contents .= gzread($handle, 1024 * 1024);

                if (strlen($contents) > $maxBytes) {
                    throw self::tooLarge($path, $maxBytes);
                }
            }
        } finally {
            gzclose($handle);
        }

        return $contents;
    }

    private static function readZip(string $path, int $maxBytes): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open zip file [{$path}].");
        }

        $preferredIndex = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name !== false && str_ends_with(strtolower($name), '.xml')) {
                $preferredIndex = $i;

                break;
            }
        }

        // Streamed and counted like gzip: the sizes in the zip directory are
        // not trusted, and getFromIndex() with a length would allocate the
        // whole cap up front.
        $stream = $zip->getStreamIndex($preferredIndex ?? 0);

        if ($stream === false) {
            $zip->close();

            throw new RuntimeException("Unable to read an entry from zip file [{$path}].");
        }

        $contents = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException("Unable to read an entry from zip file [{$path}].");
                }

                $contents .= $chunk;

                if (strlen($contents) > $maxBytes) {
                    throw self::tooLarge($path, $maxBytes);
                }
            }
        } finally {
            fclose($stream);
            $zip->close();
        }

        return $contents;
    }

    private static function tooLarge(string $path, int $maxBytes): RuntimeException
    {
        return new RuntimeException("Report [{$path}] is larger than the maximum of {$maxBytes} bytes (dmarc.max_report_bytes).");
    }
}
