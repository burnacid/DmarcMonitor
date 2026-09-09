<?php

namespace Tests\Unit;

use App\Services\Enrichment\GeoIpDatabaseUpdater;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PharData;
use Tests\TestCase;

class GeoIpDatabaseUpdaterTest extends TestCase
{
    private string $asnPath;

    private string $countryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir().'/geoip-test-'.Str::random(8);
        File::ensureDirectoryExists($dir);

        $this->asnPath = "{$dir}/GeoLite2-ASN.mmdb";
        $this->countryPath = "{$dir}/GeoLite2-Country.mmdb";

        config([
            'geoip.mmdb_path' => $this->asnPath,
            'geoip.country_mmdb_path' => $this->countryPath,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->asnPath));

        parent::tearDown();
    }

    /**
     * Build a real .tar.gz whose shape matches what MaxMind ships: a single
     * dated top-level folder containing the .mmdb file.
     */
    private function buildFixtureArchive(string $edition, string $mmdbContent): string
    {
        $workDir = sys_get_temp_dir().'/geoip-fixture-'.Str::random(8);
        $srcDir = "{$workDir}/src/{$edition}_20260101";
        File::ensureDirectoryExists($srcDir);
        File::put("{$srcDir}/{$edition}.mmdb", $mmdbContent);

        $tarPath = "{$workDir}/archive.tar";
        $phar = new PharData($tarPath);
        $phar->buildFromDirectory("{$workDir}/src");
        $phar->compress(\Phar::GZ);

        $contents = File::get("{$tarPath}.gz");
        File::deleteDirectory($workDir);

        return $contents;
    }

    public function test_skips_when_no_license_key_is_configured(): void
    {
        config(['geoip.license_key' => null]);
        Http::fake();

        $results = (new GeoIpDatabaseUpdater)->updateAll();

        $this->assertEquals(['GeoLite2-ASN' => false, 'GeoLite2-Country' => false], $results);
        Http::assertNothingSent();
    }

    public function test_downloads_and_installs_both_databases(): void
    {
        config(['geoip.license_key' => 'test-license-key']);

        Http::fake([
            'download.maxmind.com/*edition_id=GeoLite2-ASN*' => Http::response($this->buildFixtureArchive('GeoLite2-ASN', 'fake-asn-database')),
            'download.maxmind.com/*edition_id=GeoLite2-Country*' => Http::response($this->buildFixtureArchive('GeoLite2-Country', 'fake-country-database')),
        ]);

        $results = (new GeoIpDatabaseUpdater)->updateAll();

        $this->assertEquals(['GeoLite2-ASN' => true, 'GeoLite2-Country' => true], $results);
        $this->assertEquals('fake-asn-database', File::get($this->asnPath));
        $this->assertEquals('fake-country-database', File::get($this->countryPath));
    }

    public function test_failed_download_is_handled_gracefully(): void
    {
        config(['geoip.license_key' => 'test-license-key']);
        Http::fake(fn () => Http::response('forbidden', 403));

        $results = (new GeoIpDatabaseUpdater)->updateAll();

        $this->assertEquals(['GeoLite2-ASN' => false, 'GeoLite2-Country' => false], $results);
        $this->assertFileDoesNotExist($this->asnPath);
    }

    public function test_archive_missing_the_expected_mmdb_file_is_handled_gracefully(): void
    {
        config(['geoip.license_key' => 'test-license-key']);
        Http::fake(fn () => Http::response($this->buildFixtureArchive('SomeOtherFile', 'irrelevant')));

        $results = (new GeoIpDatabaseUpdater)->updateAll();

        $this->assertEquals(['GeoLite2-ASN' => false, 'GeoLite2-Country' => false], $results);
        $this->assertFileDoesNotExist($this->asnPath);
    }
}
