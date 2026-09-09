<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PharData;
use Throwable;

class GeoIpDatabaseUpdater
{
    /**
     * MaxMind edition id => the geoip config key holding its install path.
     */
    private const EDITIONS = [
        'GeoLite2-ASN' => 'mmdb_path',
        'GeoLite2-Country' => 'country_mmdb_path',
    ];

    /**
     * Download and install the latest build of every configured GeoLite2
     * database, keyed by edition id, each true (installed) or false (skipped
     * or failed — see the log for why).
     *
     * @return array<string, bool>
     */
    public function updateAll(): array
    {
        return collect(self::EDITIONS)
            ->mapWithKeys(fn (string $configKey, string $edition) => [$edition => $this->update($edition, $configKey)])
            ->all();
    }

    private function update(string $edition, string $configKey): bool
    {
        $licenseKey = config('geoip.license_key');
        $destination = config("geoip.{$configKey}");

        if (blank($licenseKey) || blank($destination)) {
            return false;
        }

        try {
            $response = Http::timeout(120)->get('https://download.maxmind.com/app/geoip_download', [
                'edition_id' => $edition,
                'license_key' => $licenseKey,
                'suffix' => 'tar.gz',
            ]);
        } catch (Throwable $e) {
            Log::warning("GeoLite2 download failed for [{$edition}]: {$e->getMessage()}");

            return false;
        }

        if (! $response->successful()) {
            Log::warning("GeoLite2 download failed for [{$edition}]: HTTP {$response->status()}");

            return false;
        }

        return $this->extractAndInstall($edition, $response->body(), $destination);
    }

    private function extractAndInstall(string $edition, string $archiveContents, string $destination): bool
    {
        $tempDir = sys_get_temp_dir().'/geoip-'.Str::random(12);
        File::ensureDirectoryExists($tempDir);
        $tarGzPath = "{$tempDir}/{$edition}.tar.gz";

        try {
            File::put($tarGzPath, $archiveContents);

            (new PharData($tarGzPath))->decompress();
            (new PharData(Str::beforeLast($tarGzPath, '.gz')))->extractTo($tempDir);

            $mmdbFiles = File::glob("{$tempDir}/*/{$edition}.mmdb");

            if (empty($mmdbFiles)) {
                Log::warning("GeoLite2 archive for [{$edition}] didn't contain the expected .mmdb file.");

                return false;
            }

            File::ensureDirectoryExists(dirname($destination));
            File::copy($mmdbFiles[0], $destination);

            return true;
        } catch (Throwable $e) {
            Log::warning("Failed to extract GeoLite2 database for [{$edition}]: {$e->getMessage()}");

            return false;
        } finally {
            File::deleteDirectory($tempDir);
        }
    }
}
