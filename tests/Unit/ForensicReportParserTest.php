<?php

namespace Tests\Unit;

use App\Models\Domain;
use App\Models\ImapAccount;
use App\Services\Dmarc\ForensicReportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class ForensicReportParserTest extends TestCase
{
    use RefreshDatabase;

    private function feedbackReportText(string $fixture): string
    {
        $message = Message::fromFile(base_path("tests/Fixtures/dmarc/forensic/{$fixture}"));

        foreach ($message->getAttachments() as $attachment) {
            if (str_starts_with(strtolower((string) $attachment->content_type), 'message/feedback-report')) {
                return $attachment->getContent();
            }
        }

        throw new \RuntimeException("Fixture [{$fixture}] has no message/feedback-report part.");
    }

    public function test_it_parses_a_complete_report_and_auto_creates_the_domain(): void
    {
        $text = $this->feedbackReportText('complete-auth-failure.eml');

        $report = (new ForensicReportParser)->parseFromMessage($text, subject: 'DMARC failure report for example.com');

        $this->assertDatabaseHas('domains', [
            'fqdn' => 'example.com',
            'is_active' => false,
        ]);

        $this->assertEquals('example.com', $report->header_from);
        $this->assertEquals('bounce@bounce.example.com', $report->envelope_from);
        $this->assertEquals('recipient@example.net', $report->envelope_to);
        $this->assertEquals('192.0.2.55', $report->source_ip);
        $this->assertEquals('20260308-envelope-12345', $report->original_envelope_id);
        $this->assertEquals('reject', $report->delivery_result);
        $this->assertEquals('example.com', $report->dkim_domain);
        $this->assertEquals('fail', $report->dkim_result);
        $this->assertEquals('bounce.example.com', $report->spf_domain);
        $this->assertEquals('fail', $report->spf_result);
        $this->assertEquals('DMARC failure report for example.com', $report->subject);
        $this->assertNotNull($report->arrival_date);
        $this->assertEquals('2026-03-08 17:03:21', $report->arrival_date->format('Y-m-d H:i:s'));
    }

    public function test_it_resolves_an_existing_active_domain_instead_of_creating_a_new_one(): void
    {
        $domain = Domain::create(['fqdn' => 'example.com', 'is_active' => true]);

        $text = $this->feedbackReportText('complete-auth-failure.eml');
        $report = (new ForensicReportParser)->parseFromMessage($text);

        $this->assertEquals($domain->id, $report->domain_id);
        $this->assertDatabaseCount('domains', 1);
    }

    public function test_it_handles_a_minimal_report_missing_optional_fields(): void
    {
        $text = $this->feedbackReportText('minimal-missing-fields.eml');

        $report = (new ForensicReportParser)->parseFromMessage($text);

        $this->assertEquals('other-example.org', $report->header_from);
        $this->assertEquals('198.51.100.23', $report->source_ip);
        $this->assertNull($report->original_envelope_id);
        $this->assertNull($report->envelope_to);
        $this->assertNull($report->dkim_domain);
        $this->assertNull($report->dkim_result);
        $this->assertNull($report->spf_domain);
        $this->assertNull($report->spf_result);
        // Delivery-Result is present but not one of the enum's valid values.
        $this->assertEquals('other', $report->delivery_result);
    }

    public function test_reprocessing_the_same_message_is_idempotent(): void
    {
        $account = ImapAccount::factory()->create();
        $parser = new ForensicReportParser;
        $text = $this->feedbackReportText('complete-auth-failure.eml');

        $first = $parser->parseFromMessage($text, $account, messageUid: '42');
        $second = $parser->parseFromMessage($text, $account, messageUid: '42');

        $this->assertEquals($first->id, $second->id);
        $this->assertDatabaseCount('forensic_reports', 1);
    }

    public function test_it_throws_when_no_domain_can_be_determined(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ForensicReportParser)->parseFromMessage("Feedback-Type: auth-failure\nSource-IP: 203.0.113.1\n");
    }
}
