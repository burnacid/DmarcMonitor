<?php

namespace Tests\Unit;

use App\Models\AggregateReport;
use App\Models\ForensicReport;
use App\Services\Eml\EmlIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MsgBuilder;
use Tests\TestCase;

class EmlIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eml-import-test-'.Str::random(8);
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function copyFixture(string $name, string $subdir = 'eml'): string
    {
        $target = $this->workDir.DIRECTORY_SEPARATOR.$name;
        copy(base_path("tests/Fixtures/dmarc/{$subdir}/{$name}"), $target);

        return $target;
    }

    public function test_it_imports_an_aggregate_report_and_moves_the_file_to_processed(): void
    {
        $path = $this->copyFixture('aggregate-report.eml');

        $stats = (new EmlIngestionService)->importPath($path);

        $this->assertEquals(['fetched' => 1, 'parsed' => 1, 'failed' => 0], $stats);
        $this->assertDatabaseCount('aggregate_reports', 1);
        $report = AggregateReport::first();
        $this->assertEquals('google.com', $report->org_name);
        $this->assertNull($report->imap_account_id);
        $this->assertNull($report->microsoft365_mail_account_id);

        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($this->workDir.'/processed/aggregate-report.eml');
    }

    public function test_it_imports_a_forensic_report_and_moves_the_file_to_processed(): void
    {
        $path = $this->copyFixture('complete-auth-failure.eml', 'forensic');

        $stats = (new EmlIngestionService)->importPath($path);

        $this->assertEquals(['fetched' => 1, 'parsed' => 1, 'failed' => 0], $stats);
        $this->assertDatabaseCount('forensic_reports', 1);
        $report = ForensicReport::first();
        $this->assertEquals('example.com', $report->header_from);

        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($this->workDir.'/processed/complete-auth-failure.eml');
    }

    public function test_a_message_with_no_recognizable_dmarc_content_is_moved_to_failed(): void
    {
        $path = $this->copyFixture('no-report.eml');

        $stats = (new EmlIngestionService)->importPath($path);

        $this->assertEquals(['fetched' => 1, 'parsed' => 0, 'failed' => 1], $stats);
        $this->assertDatabaseCount('aggregate_reports', 0);
        $this->assertDatabaseCount('forensic_reports', 0);

        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($this->workDir.'/failed/no-report.eml');
    }

    public function test_it_imports_every_eml_file_in_a_directory(): void
    {
        $this->copyFixture('aggregate-report.eml');
        $this->copyFixture('no-report.eml');

        $stats = (new EmlIngestionService)->importPath($this->workDir);

        $this->assertEquals(['fetched' => 2, 'parsed' => 1, 'failed' => 1], $stats);
    }

    private function writeMsg(string $name, string $subject, array $attachments): string
    {
        $path = $this->workDir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, MsgBuilder::build($subject, $attachments));

        return $path;
    }

    public function test_it_imports_an_aggregate_report_from_a_msg_file(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/dmarc/aggregate/google-single-record.xml'));
        $path = $this->writeMsg('report.msg', 'Report domain: example.com', [
            ['name' => 'google.com!example.com!1735689600!1735776000.xml', 'mimeType' => 'application/xml', 'content' => $xml],
        ]);

        $stats = (new EmlIngestionService)->importPath($path);

        $this->assertEquals(['fetched' => 1, 'parsed' => 1, 'failed' => 0], $stats);
        $this->assertDatabaseCount('aggregate_reports', 1);
        $this->assertFileExists($this->workDir.'/processed/report.msg');
    }

    public function test_it_reads_msg_attachments_stored_outside_the_mini_stream(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/dmarc/aggregate/google-single-record.xml'));
        $padded = str_replace('</feedback>', str_repeat('<!-- padding -->', 400).'</feedback>', $xml);
        $this->assertGreaterThan(4096, strlen($padded));

        $path = $this->writeMsg('big.msg', 'Big report', [
            ['name' => 'big.xml', 'mimeType' => 'application/xml', 'content' => $padded],
        ]);

        $stats = (new EmlIngestionService)->importPath($path);

        $this->assertEquals(['fetched' => 1, 'parsed' => 1, 'failed' => 0], $stats);
        $this->assertDatabaseCount('aggregate_reports', 1);
    }

    public function test_it_imports_a_forensic_report_from_a_msg_file(): void
    {
        $eml = file_get_contents(base_path('tests/Fixtures/dmarc/forensic/complete-auth-failure.eml'));
        preg_match('/Content-Type: message\/feedback-report\R\R(.*?)\R--RFC6591BOUNDARY/s', $eml, $matches);

        $path = $this->writeMsg('forensic.msg', 'DMARC failure report for example.com', [
            ['name' => 'Feedback report', 'mimeType' => 'message/feedback-report', 'content' => $matches[1]],
        ]);

        $stats = (new EmlIngestionService)->importPath($path);

        $this->assertEquals(['fetched' => 1, 'parsed' => 1, 'failed' => 0], $stats);
        $report = ForensicReport::first();
        $this->assertEquals('example.com', $report->header_from);
        $this->assertStringEndsWith('.msg', $report->raw_message_path);
    }

    public function test_a_msg_file_without_reports_or_an_invalid_msg_file_is_moved_to_failed(): void
    {
        $empty = $this->writeMsg('empty.msg', 'Hello', []);
        $garbage = $this->workDir.DIRECTORY_SEPARATOR.'garbage.msg';
        file_put_contents($garbage, 'not an ole file');

        $stats = (new EmlIngestionService)->importPath($this->workDir);

        $this->assertEquals(['fetched' => 2, 'parsed' => 0, 'failed' => 2], $stats);
        $this->assertFileExists($this->workDir.'/failed/empty.msg');
        $this->assertFileExists($this->workDir.'/failed/garbage.msg');
    }

    public function test_reimporting_the_same_aggregate_report_is_idempotent(): void
    {
        $first = $this->copyFixture('aggregate-report.eml');
        (new EmlIngestionService)->importPath($first);

        $second = $this->copyFixture('aggregate-report.eml');
        (new EmlIngestionService)->importPath($second);

        $this->assertDatabaseCount('aggregate_reports', 1);
    }
}
