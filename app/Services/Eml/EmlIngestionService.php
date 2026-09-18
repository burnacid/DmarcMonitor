<?php

namespace App\Services\Eml;

use App\Services\Dmarc\AggregateReportParser;
use App\Services\Dmarc\ForensicReportParser;
use App\Support\DmarcAttachmentSniffer;
use App\Support\ForensicReportDetector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Throwable;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message;

class EmlIngestionService
{
    public function __construct(
        private readonly AggregateReportParser $parser = new AggregateReportParser,
        private readonly ForensicReportParser $forensicParser = new ForensicReportParser,
    ) {}

    /**
     * Import a single .eml file, or every top-level .eml file in a directory.
     *
     * @return array{fetched: int, parsed: int, failed: int}
     */
    public function importPath(string $path): array
    {
        $stats = ['fetched' => 0, 'parsed' => 0, 'failed' => 0];

        if (is_dir($path)) {
            $files = Finder::create()->files()->in($path)->depth(0)->name('*.eml');
        } else {
            $files = [$path];
        }

        foreach ($files as $file) {
            $emlPath = is_string($file) ? $file : $file->getRealPath();
            $stats['fetched']++;

            if ($this->importFile($emlPath)) {
                $stats['parsed']++;
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function importFile(string $emlPath): bool
    {
        try {
            $message = Message::fromFile($emlPath);
        } catch (Throwable $e) {
            Log::warning("Failed to read .eml file [{$emlPath}]: {$e->getMessage()}");
            $this->moveTo($emlPath, 'failed');

            return false;
        }

        $messageUid = hash('sha256', file_get_contents($emlPath));
        $success = ForensicReportDetector::looksLikeForensicReport($message)
            ? $this->processForensicMessage($message, $emlPath, $messageUid)
            : $this->processAggregateMessage($message, $emlPath, $messageUid);

        $this->moveTo($emlPath, $success ? 'processed' : 'failed');

        return $success;
    }

    private function processAggregateMessage(Message $message, string $emlPath, string $messageUid): bool
    {
        $anyAttachmentFound = false;
        $allSucceeded = true;

        foreach ($message->getAttachments() as $attachment) {
            if (! DmarcAttachmentSniffer::isAggregateReportFilename((string) $attachment->name)) {
                continue;
            }

            $anyAttachmentFound = true;

            if (! $this->processAttachment($attachment, $emlPath, $messageUid)) {
                $allSucceeded = false;
            }
        }

        return $anyAttachmentFound ? $allSucceeded : false;
    }

    private function processAttachment(Attachment $attachment, string $emlPath, string $messageUid): bool
    {
        $storedPath = null;

        try {
            $filename = Str::uuid().'-'.Str::slug(pathinfo((string) $attachment->name, PATHINFO_FILENAME)).'.'.pathinfo((string) $attachment->name, PATHINFO_EXTENSION);
            $storedPath = "dmarc-attachments/eml-import/{$filename}";

            Storage::disk('local')->put($storedPath, $attachment->getContent());

            $this->parser->parseFile(
                Storage::disk('local')->path($storedPath),
                null,
                $storedPath,
                $messageUid,
            );

            return true;
        } catch (Throwable $e) {
            Log::warning("Failed to parse DMARC attachment [{$attachment->name}] from local file [{$emlPath}]: {$e->getMessage()}");

            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            return false;
        }
    }

    private function processForensicMessage(Message $message, string $emlPath, string $messageUid): bool
    {
        $attachment = ForensicReportDetector::feedbackReportAttachment($message);
        $storedPath = null;

        try {
            // A stable copy is kept in storage — independent of $emlPath, which
            // is about to be moved into a processed/failed sibling folder — so
            // the persisted raw_message_path keeps pointing at real content.
            $storedPath = 'dmarc-attachments/eml-import/'.Str::uuid().'.eml';
            Storage::disk('local')->put($storedPath, file_get_contents($emlPath));

            $this->forensicParser->parseFromMessage(
                $attachment->getContent(),
                null,
                $storedPath,
                $messageUid,
                (string) $message->getSubject(),
            );

            return true;
        } catch (Throwable $e) {
            Log::warning("Failed to parse forensic report from local file [{$emlPath}]: {$e->getMessage()}");

            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            return false;
        }
    }

    private function moveTo(string $emlPath, string $subfolder): void
    {
        $targetDir = dirname($emlPath).DIRECTORY_SEPARATOR.$subfolder;

        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $target = $targetDir.DIRECTORY_SEPARATOR.basename($emlPath);

        rename($emlPath, $target);
    }
}
