<?php

namespace App\Console\Commands;

use App\Services\Eml\EmlIngestionService;
use App\Support\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dmarc:import-mail-files {paths?* : .eml/.msg file(s) or directory(ies) to import; defaults to the configured inbox}')]
#[Description('Import DMARC aggregate/forensic reports from local .eml and .msg files')]
class ImportMailFiles extends Command
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

            if ($stats['fetched'] > 0) {
                AuditLogger::record(
                    action: 'ingestion.completed',
                    description: "Local .eml/.msg import [{$path}]: {$stats['parsed']} parsed, {$stats['failed']} failed, out of {$stats['fetched']} fetched",
                    userId: null,
                    context: $stats,
                );
            }
        }

        return self::SUCCESS;
    }
}
