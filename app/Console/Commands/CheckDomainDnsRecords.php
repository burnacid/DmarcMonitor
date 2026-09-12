<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Dns\DomainAuthenticationChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dmarc:check-dns {domain? : ID of a single domain to check}')]
#[Description('Check DMARC, SPF, and DKIM DNS records for active domains')]
class CheckDomainDnsRecords extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DomainAuthenticationChecker $checker): int
    {
        $domains = $this->argument('domain')
            ? Domain::where('id', $this->argument('domain'))->get()
            : Domain::where('is_active', true)->get();

        if ($domains->isEmpty()) {
            $this->info('No active domains to check.');

            return self::SUCCESS;
        }

        foreach ($domains as $domain) {
            $domain = $checker->checkAndStore($domain);

            $this->line("{$domain->fqdn}: dmarc={$domain->dmarc_status} spf={$domain->spf_status} dkim={$domain->dkim_status}");
        }

        return self::SUCCESS;
    }
}
