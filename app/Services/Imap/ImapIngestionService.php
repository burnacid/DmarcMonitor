<?php

namespace App\Services\Imap;

use App\Models\ImapAccount;
use App\Services\Dmarc\AggregateReportParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

class ImapIngestionService
{
    /**
     * Filename suffixes that identify a DMARC aggregate report attachment.
     */
    private const array AGGREGATE_REPORT_SUFFIXES = ['.xml', '.xml.gz', '.xml.zip', '.zip', '.gz'];

    /**
     * How many messages to pull (and hold in memory) from the mailbox per IMAP round-trip.
     */
    private const int CHUNK_SIZE = 20;

    /**
     * Wall-clock budget for a single pollAccount() call. A synchronous "Fetch now"
     * click runs under the web server's own request timeout, so this needs to stay
     * comfortably under that — a mailbox with more to process than fits in the
     * budget is simply picked up again on the next poll.
     */
    private const int TIME_BUDGET_SECONDS = 45;

    /**
     * Absolute cap on batches per invocation, regardless of the time budget —
     * mainly a backstop against looping forever when "also fetch already-read
     * messages" is on and nothing (no move/delete) ever removes a message from
     * the search results between batches.
     */
    private const int MAX_BATCHES_PER_RUN = 100;

    public function __construct(
        private readonly AggregateReportParser $parser = new AggregateReportParser,
    ) {}

    /**
     * Poll a single IMAP account for new aggregate report attachments, in bounded
     * batches so a large mailbox can't exhaust memory or the request time limit.
     *
     * @return array{fetched: int, parsed: int, failed: int, more_remaining: bool}
     */
    public function pollAccount(ImapAccount $account): array
    {
        $stats = ['fetched' => 0, 'parsed' => 0, 'failed' => 0, 'more_remaining' => false];
        $startedAt = microtime(true);

        $client = (new ClientManager())->make([
            'host' => $account->host,
            'port' => $account->port,
            'encryption' => $account->encryption === 'none' ? false : $account->encryption,
            'validate_cert' => true,
            'username' => $account->username,
            'password' => $account->password,
            'protocol' => $account->protocol,
        ]);

        $chunkFailure = null;

        try {
            $client->connect();
            $folder = $client->getFolderByPath($account->folder_inbox);

            if ($folder === null) {
                throw new \RuntimeException("Inbox folder [{$account->folder_inbox}] not found.");
            }

            // Each batch runs its own fresh SEARCH + FETCH cycle, rather than
            // slicing pages out of one UID list fetched up front (that's what
            // Webklex's own chunked() does): mutating already-processed messages
            // — marking read, moving, deleting — between batches was leaving the
            // connection's response parsing desynced by the time the next batch's
            // FETCH ran, surfacing as a bogus "Empty response" IMAP error. A fresh
            // SEARCH each time keeps client and server in step, and since already
            // processed messages naturally drop out of an unseen/moved-out search
            // between batches, requesting "page 1" repeatedly is enough to walk
            // through the whole backlog without needing to track a page number.
            $previousUids = null;

            for ($batch = 0; $batch < self::MAX_BATCHES_PER_RUN; $batch++) {
                if ((microtime(true) - $startedAt) > self::TIME_BUDGET_SECONDS) {
                    $stats['more_remaining'] = true;

                    break;
                }

                $query = $folder->query()->fetchOrderDesc();

                if ($account->include_read_messages) {
                    $query->whereAll();
                } else {
                    $query->whereUnseen();
                }

                try {
                    $messages = $query->limit(self::CHUNK_SIZE, 1)->get();
                } catch (Throwable $e) {
                    // A failure fetching one batch (e.g. a transient protocol
                    // hiccup) shouldn't discard progress already made on earlier
                    // batches: stop here, keep what was already saved, and let
                    // the next poll continue.
                    $stats['more_remaining'] = true;
                    $chunkFailure = $e->getMessage();

                    break;
                }

                if ($messages->isEmpty()) {
                    break;
                }

                $currentUids = $messages->map(fn ($message) => $message->getUid())->all();

                if ($previousUids === $currentUids) {
                    // Nothing changed between searches — with "also fetch
                    // already-read messages" on and no move/delete configured,
                    // nothing ever drops out of an ALL search, so this batch
                    // would just be handed to us again forever. Stop for this
                    // run rather than loop; there's nothing more to make
                    // progress on without a folder/delete setting to exclude
                    // already-seen messages next time.
                    break;
                }

                $previousUids = $currentUids;
                $stats['fetched'] += $messages->count();

                foreach ($messages as $message) {
                    $success = $this->processMessage($message, $account, $stats);
                    $this->applyPostProcessing($message, $account, $success);
                }
            }

            if ($chunkFailure !== null) {
                Log::warning("IMAP poll for account [{$account->label}] stopped early after a batch failure: {$chunkFailure}");
            }

            $account->update(['last_polled_at' => now(), 'last_error' => $chunkFailure]);
        } catch (Throwable $e) {
            Log::warning("IMAP poll failed for account [{$account->label}]: {$e->getMessage()}");
            $account->update(['last_polled_at' => now(), 'last_error' => $e->getMessage()]);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // already disconnected or never connected
            }
        }

        return $stats;
    }

    /**
     * @param  array{fetched: int, parsed: int, failed: int}  $stats
     */
    private function processMessage(Message $message, ImapAccount $account, array &$stats): bool
    {
        $anyAttachmentFound = false;
        $allSucceeded = true;

        foreach ($message->getAttachments() as $attachment) {
            if (! $this->looksLikeAggregateReport($attachment)) {
                continue;
            }

            $anyAttachmentFound = true;

            if ($this->processAttachment($attachment, $account, $message)) {
                $stats['parsed']++;
            } else {
                $stats['failed']++;
                $allSucceeded = false;
            }
        }

        return $anyAttachmentFound ? $allSucceeded : true;
    }

    private function looksLikeAggregateReport(Attachment $attachment): bool
    {
        return $this->isAggregateReportFilename((string) $attachment->name);
    }

    private function isAggregateReportFilename(string $name): bool
    {
        $name = strtolower($name);

        foreach (self::AGGREGATE_REPORT_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function processAttachment(Attachment $attachment, ImapAccount $account, Message $message): bool
    {
        $storedPath = null;

        try {
            $filename = Str::uuid().'-'.Str::slug(pathinfo((string) $attachment->name, PATHINFO_FILENAME)).'.'.pathinfo((string) $attachment->name, PATHINFO_EXTENSION);
            $storedPath = "dmarc-attachments/{$account->id}/{$filename}";

            Storage::disk('local')->put($storedPath, $attachment->getContent());

            $this->parser->parseFile(
                Storage::disk('local')->path($storedPath),
                $account,
                $storedPath,
                (string) $message->getUid(),
            );

            return true;
        } catch (Throwable $e) {
            Log::warning("Failed to parse DMARC attachment [{$attachment->name}] from account [{$account->label}]: {$e->getMessage()}");

            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            return false;
        }
    }

    private function applyPostProcessing(Message $message, ImapAccount $account, bool $success): void
    {
        try {
            if ($account->delete_after_processing) {
                $message->delete();

                return;
            }

            $targetFolder = $success ? $account->folder_processed : $account->folder_failed;

            if ($targetFolder) {
                $message->move($targetFolder);

                return;
            }

            if ($account->mark_as_read) {
                $message->setFlag('Seen');
            }
        } catch (Throwable $e) {
            Log::warning("Failed to post-process message for account [{$account->label}]: {$e->getMessage()}");
        }
    }
}
