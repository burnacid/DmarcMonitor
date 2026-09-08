<?php

namespace App\Console\Commands;

use App\Models\ImapAccount;
use App\Services\Imap\ImapIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('imap:poll {account? : ID of a single IMAP account to poll}')]
#[Description('Poll active IMAP accounts for new DMARC aggregate reports')]
class PollImapAccounts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ImapIngestionService $service): int
    {
        $accounts = $this->argument('account')
            ? ImapAccount::where('id', $this->argument('account'))->get()
            : ImapAccount::where('is_active', true)->get();

        if ($accounts->isEmpty()) {
            $this->info('No active IMAP accounts to poll.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->info("Polling [{$account->label}]...");

            $stats = $service->pollAccount($account);

            $this->line("  fetched={$stats['fetched']} parsed={$stats['parsed']} failed={$stats['failed']}");

            if ($stats['more_remaining']) {
                $this->comment('  time budget reached, more messages remain — will continue on the next poll');
            }

            if ($account->fresh()->last_error) {
                $this->error("  error: {$account->fresh()->last_error}");
            }
        }

        return self::SUCCESS;
    }
}
