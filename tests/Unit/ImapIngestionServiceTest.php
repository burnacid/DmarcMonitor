<?php

namespace Tests\Unit;

use App\Services\Imap\ImapIngestionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImapIngestionServiceTest extends TestCase
{
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
}
