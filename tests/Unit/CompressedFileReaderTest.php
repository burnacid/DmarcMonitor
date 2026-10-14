<?php

namespace Tests\Unit;

use App\Support\CompressedFileReader;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class CompressedFileReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'compressed-reader-'.uniqid();
        mkdir($this->directory);
        config(['dmarc.max_report_bytes' => 1024]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_a_report_within_the_limit_is_read(): void
    {
        config(['dmarc.max_report_bytes' => 25 * 1024 * 1024]);

        $xml = CompressedFileReader::read(base_path('tests/Fixtures/dmarc/aggregate/google-single-record.xml.zip'));

        $this->assertStringContainsString('<feedback', $xml);
    }

    public function test_a_gzip_file_expanding_past_the_limit_is_rejected(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'bomb.xml.gz';
        file_put_contents($path, gzencode(str_repeat('A', 10 * 1024)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('larger than the maximum');

        CompressedFileReader::read($path);
    }

    public function test_a_zip_entry_expanding_past_the_limit_is_rejected(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'bomb.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('report.xml', str_repeat('A', 10 * 1024));
        $zip->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('larger than the maximum');

        CompressedFileReader::read($path);
    }

    public function test_an_uncompressed_file_past_the_limit_is_rejected(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'report.xml';
        file_put_contents($path, str_repeat('A', 2048));

        $this->expectException(RuntimeException::class);

        CompressedFileReader::read($path);
    }
}
