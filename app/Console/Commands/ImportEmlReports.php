<?php

namespace App\Console\Commands;

use App\Services\Eml\EmlIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dmarc:import-eml {paths?* : .eml file(s) or directory(ies) to import; defaults to the configured inbox}')]
#[Description('Import DMARC aggregate/forensic reports from local .eml files')]
class ImportEmlReports extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EmlIngestionService $service): int
    {
        $paths = $this->argument('paths');

        if (empty($paths)) {
            $inbox = rtrim((string) config('dmarc.eml_import_path'), '/\\').DIRECTORY_SEPARATOR.'inbox';

            if (! is_dir($inbox)) {
                mkdir($inbox, 0755, true);
            }

            $paths = [$inbox];
        }

        foreach ($paths as $path) {
            if (! file_exists($path)) {
                $this->error("Path not found: {$path}");

                continue;
            }

            $stats = $service->importPath($path);

            $this->line("[{$path}] fetched={$stats['fetched']} parsed={$stats['parsed']} failed={$stats['failed']}");
        }

        return self::SUCCESS;
    }
}
