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
     */
    public static function read(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'gz' => self::readGzip($path),
            'zip' => self::readZip($path),
            default => file_get_contents($path),
        };
    }

    private static function readGzip(string $path): string
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

    private static function readZip(string $path): string
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

        $contents = $zip->getFromIndex($preferredIndex ?? 0);
        $zip->close();

        if ($contents === false) {
            throw new RuntimeException("Unable to read an entry from zip file [{$path}].");
        }

        return $contents;
    }
}
