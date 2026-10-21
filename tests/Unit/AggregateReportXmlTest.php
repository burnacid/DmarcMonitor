<?php

namespace Tests\Unit;

use App\Support\AggregateReportXml;
use PHPUnit\Framework\TestCase;

class AggregateReportXmlTest extends TestCase
{
    public function test_records_are_returned_in_document_order_also_with_a_namespace(): void
    {
        $xml = '<feedback xmlns="urn:ietf:params:xml:ns:dmarc-2.0"><report_metadata/>'
            .'<record><row><source_ip>203.0.113.1</source_ip></row></record>'
            .'<record><row><source_ip>198.51.100.2</source_ip></row></record></feedback>';

        $records = AggregateReportXml::records($xml);

        $this->assertCount(2, $records);
        $this->assertStringContainsString('<source_ip>203.0.113.1</source_ip>', $records[0]);
        $this->assertStringContainsString('<source_ip>198.51.100.2</source_ip>', $records[1]);
    }

    public function test_malformed_xml_has_no_records_and_is_shown_unchanged(): void
    {
        $this->assertSame([], AggregateReportXml::records('<feedback><record>'));
        $this->assertSame('<feedback><record>', AggregateReportXml::pretty('<feedback><record>'));
    }
}
