<?php

namespace App\Console\Commands;

use App\Models\Microsoft365MailAccount;
use App\Services\Graph\GraphIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('graph-mail:poll {account? : ID of a single Microsoft 365 mail account to poll}')]
#[Description('Poll active Microsoft 365 (Graph) mail accounts for new DMARC aggregate and forensic reports')]
class PollGraphMailAccounts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GraphIngestionService $service): int
    {
        $accounts = $this->argument('account')
            ? Microsoft365MailAccount::where('id', $this->argument('account'))->get()
            : Microsoft365MailAccount::where('is_active', true)->get();

        if ($accounts->isEmpty()) {
            $this->info('No active Microsoft 365 mail accounts to poll.');

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
