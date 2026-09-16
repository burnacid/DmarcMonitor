<?php

namespace Tests\Unit;

use App\Models\AggregateReport;
use App\Models\ForensicReport;
use App\Models\Microsoft365MailAccount;
use App\Services\Graph\GraphIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GraphIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_an_aggregate_report_attachment_and_links_it_to_the_account(): void
    {
        Storage::fake('local');

        $xml = file_get_contents(base_path('tests/Fixtures/dmarc/aggregate/google-single-record.xml'));

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/mailFolders?*' => Http::response(['value' => [['id' => 'inbox-id']]]),
            'graph.microsoft.com/v1.0/users/*/mailFolders/*/messages*' => Http::response([
                'value' => [
                    ['id' => 'msg-1', 'subject' => 'DMARC report', 'hasAttachments' => true, 'isRead' => false],
                ],
            ]),
            'graph.microsoft.com/v1.0/users/*/messages/*/attachments*' => Http::response([
                'value' => [
                    ['name' => 'google.com!example.com!1735689600!1735776000.xml', 'contentType' => 'application/octet-stream', 'contentBytes' => base64_encode($xml)],
                ],
            ]),
            'graph.microsoft.com/v1.0/users/*/messages/*' => Http::response([]),
        ]);

        $account = Microsoft365MailAccount::factory()->create();

        $stats = (new GraphIngestionService)->pollAccount($account);

        $this->assertSame(1, $stats['fetched']);
        $this->assertSame(1, $stats['parsed']);
        $this->assertSame(0, $stats['failed']);

        $report = AggregateReport::first();
        $this->assertNotNull($report);
        $this->assertSame($account->id, $report->microsoft365_mail_account_id);
        $this->assertNull($report->imap_account_id);

        $account->refresh();
        $this->assertNull($account->last_error);
        $this->assertNotNull($account->last_polled_at);
    }

    public function test_it_parses_a_forensic_report_from_a_feedback_report_attachment(): void
    {
        Storage::fake('local');

        $eml = file_get_contents(base_path('tests/Fixtures/dmarc/forensic/complete-auth-failure.eml'));
        preg_match('/Content-Type: message\/feedback-report\r?\n\r?\n(.*?)\r?\n--RFC6591BOUNDARY/s', $eml, $matches);
        $feedbackText = trim($matches[1]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/mailFolders?*' => Http::response(['value' => [['id' => 'inbox-id']]]),
            'graph.microsoft.com/v1.0/users/*/mailFolders/*/messages*' => Http::response([
                'value' => [
                    ['id' => 'msg-2', 'subject' => 'DMARC failure report for example.com', 'hasAttachments' => true, 'isRead' => false],
                ],
            ]),
            'graph.microsoft.com/v1.0/users/*/messages/*/attachments*' => Http::response([
                'value' => [
                    ['name' => 'report.txt', 'contentType' => 'message/feedback-report', 'contentBytes' => base64_encode($feedbackText)],
                ],
            ]),
            'graph.microsoft.com/v1.0/users/*/messages/*/$value' => Http::response($eml),
            'graph.microsoft.com/v1.0/users/*/messages/*' => Http::response([]),
        ]);

        $account = Microsoft365MailAccount::factory()->create();

        $stats = (new GraphIngestionService)->pollAccount($account);

        $this->assertSame(1, $stats['parsed']);
        $this->assertSame(0, $stats['failed']);

        $report = ForensicReport::first();
        $this->assertNotNull($report);
        $this->assertSame($account->id, $report->microsoft365_mail_account_id);
        $this->assertSame('example.com', $report->header_from);
    }

    public function test_it_records_a_graph_error_without_crashing(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $account = Microsoft365MailAccount::factory()->create();

        $stats = (new GraphIngestionService)->pollAccount($account);

        $this->assertSame(0, $stats['fetched']);
        $account->refresh();
        $this->assertNotNull($account->last_error);
    }
}
