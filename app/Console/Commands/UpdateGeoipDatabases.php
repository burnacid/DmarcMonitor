<?php

namespace App\Console\Commands;

use App\Services\Enrichment\GeoIpDatabaseUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dmarc:update-geoip')]
#[Description('Download the latest GeoLite2 ASN and Country databases from MaxMind')]
class UpdateGeoipDatabases extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GeoIpDatabaseUpdater $updater): int
    {
        if (blank(config('geoip.license_key'))) {
            $this->comment('No MAXMIND_LICENSE_KEY configured — skipping GeoLite2 update.');

            return self::SUCCESS;
        }

        foreach ($updater->updateAll() as $edition => $installed) {
            $this->line($installed ? "  {$edition}: updated" : "  {$edition}: skipped or failed (see log)");
        }

        return self::SUCCESS;
    }
}
