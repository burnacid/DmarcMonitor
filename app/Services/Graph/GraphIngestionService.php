<?php

namespace App\Services\Graph;

use App\Models\Microsoft365MailAccount;
use App\Services\Dmarc\AggregateReportParser;
use App\Services\Dmarc\ForensicReportParser;
use App\Support\DmarcAttachmentSniffer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GraphIngestionService
{
    /**
     * How many messages to pull per Graph round-trip.
     */
    private const int CHUNK_SIZE = 20;

    /**
     * Wall-clock budget for a single pollAccount() call, matching
     * ImapIngestionService — keeps a synchronous "Fetch now" click comfortably
     * under the web server's own request timeout.
     */
    private const int TIME_BUDGET_SECONDS = 45;

    /**
     * Absolute cap on pages fetched per invocation, regardless of the time
     * budget.
     */
    private const int MAX_BATCHES_PER_RUN = 100;

    /**
     * @var array<string, ?string>
     */
    private array $folderIdCache = [];

    public function __construct(
        private readonly GraphTokenService $tokenService = new GraphTokenService,
        private readonly AggregateReportParser $parser = new AggregateReportParser,
        private readonly ForensicReportParser $forensicParser = new ForensicReportParser,
    ) {}

    /**
     * Poll a single Microsoft 365 mailbox (via Microsoft Graph) for new
     * aggregate report attachments and forensic (ARF) reports, in bounded
     * batches so a large mailbox can't exhaust memory or the request time
     * limit.
     *
     * @return array{fetched: int, parsed: int, failed: int, more_remaining: bool}
     */
    public function pollAccount(Microsoft365MailAccount $account): array
    {
        $stats = ['fetched' => 0, 'parsed' => 0, 'failed' => 0, 'more_remaining' => false];
        $startedAt = microtime(true);
        $chunkFailure = null;

        try {
            $token = $this->tokenService->getAccessTokenFor($account);
            $mailbox = rawurlencode($account->mailbox);

            $folderId = $this->resolveFolderId($mailbox, $token, $account->folder_inbox);

            if ($folderId === null) {
                throw new RuntimeException("Inbox folder [{$account->folder_inbox}] not found.");
            }

            $url = 'https://graph.microsoft.com/v1.0/users/'.$mailbox."/mailFolders/{$folderId}/messages?".http_build_query(array_filter([
                '$top' => self::CHUNK_SIZE,
                '$select' => 'id,subject,hasAttachments,isRead',
                '$filter' => $account->include_read_messages ? null : 'isRead eq false',
            ]));

            for ($batch = 0; $batch < self::MAX_BATCHES_PER_RUN && $url; $batch++) {
                if ((microtime(true) - $startedAt) > self::TIME_BUDGET_SECONDS) {
                    $stats['more_remaining'] = true;

                    break;
                }

                try {
                    $response = Http::withToken($token)->get($url);

                    if ($response->failed()) {
                        throw new RuntimeException($response->json('error.message') ?? $response->body());
                    }
                } catch (Throwable $e) {
                    // A failure fetching one page shouldn't discard progress
                    // already made on earlier pages: stop here, keep what was
                    // already saved, and let the next poll continue.
                    $stats['more_remaining'] = true;
                    $chunkFailure = $e->getMessage();

                    break;
                }

                $messages = $response->json('value') ?? [];
                $url = $response->json('@odata.nextLink');

                if (empty($messages)) {
                    break;
                }

                $stats['fetched'] += count($messages);

                foreach ($messages as $message) {
                    $success = $this->processMessage($message, $account, $token, $mailbox, $stats);
                    $this->applyPostProcessing($message['id'], $account, $token, $mailbox, $success);
                }
            }

            if ($chunkFailure !== null) {
                Log::warning("Microsoft 365 poll for account [{$account->label}] stopped early after a batch failure: {$chunkFailure}");
            }

            $account->update(['last_polled_at' => now(), 'last_error' => $chunkFailure]);
        } catch (Throwable $e) {
            Log::warning("Microsoft 365 poll failed for account [{$account->label}]: {$e->getMessage()}");
            $account->update(['last_polled_at' => now(), 'last_error' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array{fetched: int, parsed: int, failed: int}  $stats
     */
    private function processMessage(array $message, Microsoft365MailAccount $account, string $token, string $mailbox, array &$stats): bool
    {
        if (! ($message['hasAttachments'] ?? false)) {
            return true;
        }

        // Only base microsoft.graph.attachment properties may be selected on the
        // collection: contentBytes exists solely on fileAttachment, so Graph
        // rejects it here. Content is downloaded per attachment instead.
        $attachmentsResponse = Http::withToken($token)->get(
            "https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$message['id']}/attachments",
            ['$select' => 'id,name,contentType,size'],
        );

        if ($attachmentsResponse->failed()) {
            Log::warning("Failed to list attachments for a message in account [{$account->label}]: ".($attachmentsResponse->json('error.message') ?? $attachmentsResponse->body()));
            $stats['failed']++;

            return false;
        }

        $attachments = $attachmentsResponse->json('value') ?? [];

        $feedbackAttachment = $this->feedbackReportAttachment($attachments);

        if ($feedbackAttachment !== null) {
            if ($this->processForensicMessage($feedbackAttachment, $message, $account, $token, $mailbox)) {
                $stats['parsed']++;

                return true;
            }

            $stats['failed']++;

            return false;
        }

        $anyAttachmentFound = false;
        $allSucceeded = true;

        foreach ($attachments as $attachment) {
            if (! DmarcAttachmentSniffer::isAggregateReportFilename((string) ($attachment['name'] ?? ''))) {
                continue;
            }

            $anyAttachmentFound = true;

            if ($this->processAttachment($attachment, $account, $message, $token, $mailbox)) {
                $stats['parsed']++;
            } else {
                $stats['failed']++;
                $allSucceeded = false;
            }
        }

        return $anyAttachmentFound ? $allSucceeded : true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @return ?array<string, mixed>
     */
    private function feedbackReportAttachment(array $attachments): ?array
    {
        foreach ($attachments as $attachment) {
            if (str_starts_with(strtolower((string) ($attachment['contentType'] ?? '')), 'message/feedback-report')) {
                return $attachment;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attachment
     * @param  array<string, mixed>  $message
     */
    private function processAttachment(array $attachment, Microsoft365MailAccount $account, array $message, string $token, string $mailbox): bool
    {
        $storedPath = null;
        $name = (string) ($attachment['name'] ?? 'report');

        try {
            $content = $this->downloadAttachment($attachment, $message, $token, $mailbox);

            $filename = Str::uuid().'-'.Str::slug(pathinfo($name, PATHINFO_FILENAME)).'.'.pathinfo($name, PATHINFO_EXTENSION);
            $storedPath = "dmarc-attachments/graph-{$account->id}/{$filename}";

            Storage::disk('local')->put($storedPath, $content);

            $this->parser->parseFile(
                Storage::disk('local')->path($storedPath),
                $account,
                $storedPath,
                (string) $message['id'],
            );

            return true;
        } catch (Throwable $e) {
            Log::warning("Failed to parse DMARC attachment [{$name}] from account [{$account->label}]: {$e->getMessage()}");

            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $feedbackAttachment
     * @param  array<string, mixed>  $message
     */
    private function processForensicMessage(array $feedbackAttachment, array $message, Microsoft365MailAccount $account, string $token, string $mailbox): bool
    {
        $storedPath = null;

        try {
            $feedbackText = $this->downloadAttachment($feedbackAttachment, $message, $token, $mailbox);

            $rawResponse = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$message['id']}/\$value");
            $rawMessage = $rawResponse->successful() ? $rawResponse->body() : $feedbackText;

            $storedPath = "dmarc-attachments/graph-{$account->id}/".Str::uuid().'.eml';
            Storage::disk('local')->put($storedPath, $rawMessage);

            $this->forensicParser->parseFromMessage(
                $feedbackText,
                $account,
                $storedPath,
                (string) $message['id'],
                (string) ($message['subject'] ?? ''),
            );

            return true;
        } catch (Throwable $e) {
            Log::warning("Failed to parse forensic report from account [{$account->label}]: {$e->getMessage()}");

            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            return false;
        }
    }

    /**
     * Downloads an attachment's raw bytes via its /$value endpoint, which works
     * for every attachment type and avoids base64-inflated JSON payloads.
     *
     * @param  array<string, mixed>  $attachment
     * @param  array<string, mixed>  $message
     */
    private function downloadAttachment(array $attachment, array $message, string $token, string $mailbox): string
    {
        $name = (string) ($attachment['name'] ?? 'report');
        $attachmentId = (string) ($attachment['id'] ?? '');

        if ($attachmentId === '') {
            throw new RuntimeException("Attachment [{$name}] has no id.");
        }

        $response = Http::withToken($token)->get(
            "https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$message['id']}/attachments/".rawurlencode($attachmentId).'/$value',
        );

        if ($response->failed()) {
            throw new RuntimeException("Failed to download attachment [{$name}]: ".($response->json('error.message') ?? $response->body()));
        }

        return $response->body();
    }

    private function applyPostProcessing(string $messageId, Microsoft365MailAccount $account, string $token, string $mailbox, bool $success): void
    {
        try {
            if ($account->delete_after_processing) {
                Http::withToken($token)->delete("https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$messageId}");

                return;
            }

            if ($account->mark_as_read) {
                Http::withToken($token)->patch(
                    "https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$messageId}",
                    ['isRead' => true],
                );
            }

            $targetFolderName = $success ? $account->folder_processed : $account->folder_failed;

            if ($targetFolderName) {
                $targetFolderId = $this->resolveFolderId($mailbox, $token, $targetFolderName);

                if ($targetFolderId !== null) {
                    Http::withToken($token)->post(
                        "https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$messageId}/move",
                        ['destinationId' => $targetFolderId],
                    );
                }
            }
        } catch (Throwable $e) {
            Log::warning("Failed to post-process message for account [{$account->label}]: {$e->getMessage()}");
        }
    }

    /**
     * Resolves a mail folder's display name to its Graph id, caching the
     * result for the lifetime of this service instance so a batch of
     * messages doesn't re-resolve the same folder repeatedly.
     */
    private function resolveFolderId(string $mailbox, string $token, string $folderName): ?string
    {
        $cacheKey = $mailbox.'|'.strtolower($folderName);

        if (array_key_exists($cacheKey, $this->folderIdCache)) {
            return $this->folderIdCache[$cacheKey];
        }

        $response = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$mailbox}/mailFolders", [
            '$filter' => "displayName eq '".str_replace("'", "''", $folderName)."'",
            '$select' => 'id,displayName',
        ]);

        $id = $response->successful() ? $response->json('value.0.id') : null;

        return $this->folderIdCache[$cacheKey] = $id;
    }
}
