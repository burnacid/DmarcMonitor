<?php

namespace App\Services\Smtp;

use App\Services\Eml\EmlIngestionService;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IncomingMailHandler
{
    public function __construct(private readonly EmlIngestionService $ingestion) {}

    /**
     * Spool the raw message into the local import inbox, then import it. The
     * return value says whether the message was safely stored (not whether it
     * parsed) so the SMTP sender is only asked to retry on storage failures;
     * unparseable mail ends up in the `failed/` folder like any other import.
     */
    public function __invoke(string $rawMessage): bool
    {
        $inbox = rtrim((string) config('dmarc.eml_import_path'), '/\\').DIRECTORY_SEPARATOR.'inbox';
        $path = $inbox.DIRECTORY_SEPARATOR.Str::uuid().'.eml';
        $temporaryPath = $path.'.part';

        try {
            if (! is_dir($inbox)) {
                mkdir($inbox, 0755, true);
            }

            if (file_put_contents($temporaryPath, $rawMessage) === false || ! rename($temporaryPath, $path)) {
                throw new \RuntimeException("Unable to write [{$path}].");
            }
        } catch (Throwable $e) {
            Log::error("SMTP listener failed to spool a message: {$e->getMessage()}");

            return false;
        }

        try {
            $stats = $this->ingestion->importPath($path);

            AuditLogger::record(
                action: 'ingestion.completed',
                description: "SMTP import: {$stats['parsed']} parsed, {$stats['failed']} failed, out of {$stats['fetched']} fetched",
                userId: null,
                context: $stats,
            );
        } catch (Throwable $e) {
            // The spooled file is still in the inbox for the scheduled import to retry.
            Log::error("SMTP listener failed to import [{$path}]: {$e->getMessage()}");
        }

        return true;
    }
}
