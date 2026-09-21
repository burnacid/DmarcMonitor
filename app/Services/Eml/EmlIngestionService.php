<?php

namespace App\Services\Eml;

use App\Services\Dmarc\AggregateReportParser;
use App\Services\Dmarc\ForensicReportParser;
use App\Support\DmarcAttachmentSniffer;
use App\Support\OutlookMsgReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Throwable;
use Webklex\PHPIMAP\Message;

class EmlIngestionService
{
    public function __construct(
        private readonly AggregateReportParser $parser = new AggregateReportParser,
        private readonly ForensicReportParser $forensicParser = new ForensicReportParser,
    ) {}

    /**
     * Import a single .eml/.msg file, or every top-level .eml/.msg file in a directory.
     *
     * @return array{fetched: int, parsed: int, failed: int}
     */
    public function importPath(string $path): array
    {
        $stats = ['fetched' => 0, 'parsed' => 0, 'failed' => 0];

        if (is_dir($path)) {
            $files = Finder::create()->files()->in($path)->depth(0)->name('/\.(eml|msg)$/i');
        } else {
            $files = [$path];
        }

        foreach ($files as $file) {
            $filePath = is_string($file) ? $file : $file->getRealPath();
            $stats['fetched']++;

            if ($this->importFile($filePath)) {
                $stats['parsed']++;
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function importFile(string $filePath): bool
    {
        try {
            $message = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'msg'
                ? $this->readMsg($filePath)
                : $this->readEml($filePath);
        } catch (Throwable $e) {
            Log::warning("Failed to read mail file [{$filePath}]: {$e->getMessage()}");
            $this->moveTo($filePath, 'failed');

            return false;
        }

        $messageUid = hash('sha256', file_get_contents($filePath));
        $feedbackReport = $this->feedbackReportContent($message['attachments']);
        $success = $feedbackReport !== null
            ? $this->processForensicMessage($feedbackReport, $message['subject'], $filePath, $messageUid)
            : $this->processAggregateMessage($message['attachments'], $filePath, $messageUid);

        $this->moveTo($filePath, $success ? 'processed' : 'failed');

        return $success;
    }

    /**
     * @return array{subject: string, attachments: list<array{name: string, mimeType: string, content: string}>}
     */
    private function readEml(string $filePath): array
    {
        $message = Message::fromFile($filePath);
        $attachments = [];

        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = [
                'name' => (string) $attachment->name,
                'mimeType' => (string) $attachment->content_type,
                'content' => $attachment->getContent(),
            ];
        }

        return ['subject' => (string) $message->getSubject(), 'attachments' => $attachments];
    }

    /**
     * @return array{subject: string, attachments: list<array{name: string, mimeType: string, content: string}>}
     */
    private function readMsg(string $filePath): array
    {
        $message = OutlookMsgReader::fromFile($filePath);

        return ['subject' => (string) $message->subject(), 'attachments' => $message->attachments()];
    }

    /**
     * @param  list<array{name: string, mimeType: string, content: string}>  $attachments
     */
    private function feedbackReportContent(array $attachments): ?string
    {
        foreach ($attachments as $attachment) {
            if (str_starts_with(strtolower($attachment['mimeType']), 'message/feedback-report')) {
                return $attachment['content'];
            }
        }

        return null;
    }

    /**
     * @param  list<array{name: string, mimeType: string, content: string}>  $attachments
     */
    private function processAggregateMessage(array $attachments, string $filePath, string $messageUid): bool
    {
        $anyAttachmentFound = false;
        $allSucceeded = true;

        foreach ($attachments as $attachment) {
            if (! DmarcAttachmentSniffer::isAggregateReportFilename($attachment['name'])) {
                continue;
            }

            $anyAttachmentFound = true;

            if (! $this->processAttachment($attachment['name'], $attachment['content'], $filePath, $messageUid)) {
                $allSucceeded = false;
            }
        }

        return $anyAttachmentFound ? $allSucceeded : false;
    }

    private function processAttachment(string $name, string $content, string $filePath, string $messageUid): bool
    {
        $storedPath = null;

        try {
            $filename = Str::uuid().'-'.Str::slug(pathinfo($name, PATHINFO_FILENAME)).'.'.pathinfo($name, PATHINFO_EXTENSION);
            $storedPath = "dmarc-attachments/eml-import/{$filename}";

            Storage::disk('local')->put($storedPath, $content);

            $this->parser->parseFile(
                Storage::disk('local')->path($storedPath),
                null,
                $storedPath,
                $messageUid,
            );

            return true;
        } catch (Throwable $e) {
            Log::warning("Failed to parse DMARC attachment [{$name}] from local file [{$filePath}]: {$e->getMessage()}");

            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            return false;
        }
    }

    private function processForensicMessage(string $feedbackReport, string $subject, string $filePath, string $messageUid): bool
    {
        $storedPath = null;

        try {
            // A stable copy is kept in storage — independent of $filePath, which
            // is about to be moved into a processed/failed sibling folder — so
            // the persisted raw_message_path keeps pointing at real content.
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'msg' ? 'msg' : 'eml';
            $storedPath = 'dmarc-attachments/eml-import/'.Str::uuid().'.'.$extension;
            Storage::disk('local')->put($storedPath, file_get_contents($filePath));

            $this->forensicParser->parseFromMessage(
                $feedbackReport,
                null,
                $storedPath,
                $messageUid,
                $subject,
            );

            return true;
        } catch (Throwable $e) {
            Log::warning("Failed to parse forensic report from local file [{$filePath}]: {$e->getMessage()}");

            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            return false;
        }
    }

    private function moveTo(string $filePath, string $subfolder): void
    {
        $targetDir = dirname($filePath).DIRECTORY_SEPARATOR.$subfolder;

        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $target = $targetDir.DIRECTORY_SEPARATOR.basename($filePath);

        rename($filePath, $target);
    }
}
