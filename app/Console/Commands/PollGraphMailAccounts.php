<?php

namespace App\Console\Commands;

use App\Models\Microsoft365MailAccount;
use App\Services\Graph\GraphIngestionService;
use App\Support\AuditLogger;
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

            $lastError = $account->fresh()->last_error;

            if ($stats['fetched'] > 0 || $stats['failed'] > 0) {
                AuditLogger::record(
                    action: 'ingestion.completed',
                    description: "Microsoft 365 poll [{$account->label}]: {$stats['parsed']} parsed, {$stats['failed']} failed, out of {$stats['fetched']} fetched",
                    subject: $account,
                    userId: null,
                    context: $stats,
                );
            } elseif ($lastError) {
                AuditLogger::record(
                    action: 'ingestion.failed',
                    description: "Microsoft 365 poll [{$account->label}] failed: {$lastError}",
                    subject: $account,
                    userId: null,
                );
            }

            if ($stats['more_remaining']) {
                $this->comment('  time budget reached, more messages remain — will continue on the next poll');
            }

            if ($lastError) {
                $this->error("  error: {$lastError}");
            }
        }

        return self::SUCCESS;
    }
}
