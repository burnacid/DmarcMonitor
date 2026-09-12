<?php

namespace Tests\Unit;

use App\Models\ForensicReport;
use App\Models\ImapAccount;
use App\Services\Imap\ImapIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class ImapIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('attachmentNameProvider')]
    public function test_it_recognizes_dmarc_aggregate_report_attachment_names(string $name, bool $expected): void
    {
        $service = new ImapIngestionService;
        $method = new \ReflectionMethod($service, 'isAggregateReportFilename');

        $this->assertSame($expected, $method->invoke($service, $name));
    }

    public static function attachmentNameProvider(): array
    {
        return [
            'plain xml' => ['google.com!example.com!1735689600!1735776000.xml', true],
            'gzipped xml' => ['report.xml.gz', true],
            'zipped' => ['report.zip', true],
            'gzip without xml' => ['report.gz', true],
            'uppercase extension' => ['REPORT.XML.GZ', true],
            'unrelated pdf' => ['invoice.pdf', false],
            'unrelated image' => ['logo.png', false],
            'no extension' => ['report', false],
        ];
    }

    private function forensicFixture(string $name): Message
    {
        return Message::fromFile(base_path("tests/Fixtures/dmarc/forensic/{$name}"));
    }

    public function test_it_recognizes_a_forensic_arf_message(): void
    {
        $service = new ImapIngestionService;
        $method = new \ReflectionMethod($service, 'looksLikeForensicReport');

        $this->assertTrue($method->invoke($service, $this->forensicFixture('complete-auth-failure.eml')));
    }

    public function test_it_does_not_mistake_an_aggregate_attachment_message_for_forensic(): void
    {
        $service = new ImapIngestionService;
        $message = Message::fromString(
            "From: reporter@example.com\r\n".
            "Subject: report\r\n".
            "Content-Type: multipart/mixed; boundary=\"b\"\r\n\r\n".
            "--b\r\n".
            "Content-Type: application/gzip\r\n".
            "Content-Disposition: attachment; filename=\"report.xml.gz\"\r\n\r\n".
            "not-really-gzip\r\n".
            "--b--\r\n"
        );

        $method = new \ReflectionMethod($service, 'looksLikeForensicReport');

        $this->assertFalse($method->invoke($service, $message));
    }

    public function test_processing_a_forensic_message_stores_the_report_and_raw_eml(): void
    {
        Storage::fake('local');

        $account = ImapAccount::factory()->create();
        $service = new ImapIngestionService;
        $message = $this->forensicFixture('complete-auth-failure.eml');
        $stats = ['fetched' => 0, 'parsed' => 0, 'failed' => 0, 'more_remaining' => false];

        $method = new \ReflectionMethod($service, 'processMessage');
        $success = $method->invokeArgs($service, [$message, $account, &$stats]);

        $this->assertTrue($success);
        $this->assertEquals(1, $stats['parsed']);
        $this->assertEquals(0, $stats['failed']);

        $this->assertDatabaseCount('forensic_reports', 1);
        $report = ForensicReport::first();
        $this->assertEquals($account->id, $report->imap_account_id);
        $this->assertEquals('example.com', $report->header_from);
        Storage::disk('local')->assertExists($report->raw_message_path);
    }
}
