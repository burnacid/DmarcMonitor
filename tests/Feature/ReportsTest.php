<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_reports_pages(): void
    {
        $report = AggregateReport::factory()->create();

        $this->get('/reports')->assertRedirect('/login');
        $this->get("/reports/{$report->id}")->assertRedirect('/login');
    }

    public function test_reports_index_lists_reports_with_record_and_message_counts(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'org_name' => 'Acme Mail',
        ]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'count' => 5]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'count' => 3]);

        Volt::actingAs($user)->test('reports.index')
            ->assertSee('example.com')
            ->assertSee('Acme Mail')
            ->assertSee('2') // records_count
            ->assertSee('8'); // message_count
    }

    public function test_reports_index_filters_by_domain(): void
    {
        $user = User::factory()->create();
        $domainA = Domain::factory()->create(['fqdn' => 'a.com']);
        $domainB = Domain::factory()->create(['fqdn' => 'b.com']);
        AggregateReport::factory()->create(['domain_id' => $domainA->id, 'org_name' => 'Org A']);
        AggregateReport::factory()->create(['domain_id' => $domainB->id, 'org_name' => 'Org B']);

        Volt::actingAs($user)->test('reports.index')
            ->set('domain_id', $domainA->id)
            ->assertSee('Org A')
            ->assertDontSee('Org B');
    }

    public function test_reports_index_filters_by_source_ip(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();

        $matching = AggregateReport::factory()->create(['domain_id' => $domain->id, 'org_name' => 'Matching Org']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $matching->id, 'source_ip' => '203.0.113.9']);

        $other = AggregateReport::factory()->create(['domain_id' => $domain->id, 'org_name' => 'Other Org']);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $other->id, 'source_ip' => '198.51.100.1']);

        Volt::actingAs($user)->test('reports.index')
            ->set('ip', '203.0.113.9')
            ->assertSee('Matching Org')
            ->assertDontSee('Other Org');
    }

    public function test_reports_index_filters_by_envelope_from_or_to(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();

        $matching = AggregateReport::factory()->create(['domain_id' => $domain->id, 'org_name' => 'Matching Org']);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $matching->id,
            'envelope_from' => 'bounce.example.com',
            'envelope_to' => 'recipient.other.net',
        ]);

        $other = AggregateReport::factory()->create(['domain_id' => $domain->id, 'org_name' => 'Other Org']);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $other->id,
            'envelope_from' => 'unrelated.net',
            'envelope_to' => 'unrelated-too.net',
        ]);

        Volt::actingAs($user)->test('reports.index')
            ->set('envelope', 'example.com')
            ->assertSee('Matching Org')
            ->assertDontSee('Other Org');

        Volt::actingAs($user)->test('reports.index')
            ->set('envelope', 'recipient.other.net')
            ->assertSee('Matching Org')
            ->assertDontSee('Other Org');
    }

    public function test_reports_show_displays_report_details_and_records(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'org_name' => 'Acme Mail',
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.9',
            'header_from' => 'example.com',
            'envelope_to' => 'recipient.example.net',
        ]);

        Volt::actingAs($user)->test('reports.show', ['report' => $report])
            ->assertSee('Acme Mail')
            ->assertSee('example.com')
            ->assertSee('203.0.113.9')
            ->assertSee('recipient.example.net');
    }

    public function test_raw_xml_can_be_downloaded(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('dmarc-attachments/report.xml', '<feedback></feedback>');

        $user = User::factory()->create();
        $report = AggregateReport::factory()->create([
            'raw_xml_path' => 'dmarc-attachments/report.xml',
        ]);

        $response = $this->actingAs($user)->get(route('reports.download', $report));

        $response->assertOk();
        $this->assertEquals('<feedback></feedback>', $response->streamedContent());
    }

    public function test_gzip_compressed_raw_xml_is_decompressed_on_download(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('dmarc-attachments/report.xml.gz', gzencode('<feedback>gzipped</feedback>'));

        $user = User::factory()->create();
        $report = AggregateReport::factory()->create([
            'raw_xml_path' => 'dmarc-attachments/report.xml.gz',
        ]);

        $response = $this->actingAs($user)->get(route('reports.download', $report));

        $response->assertOk();
        $this->assertEquals('<feedback>gzipped</feedback>', $response->streamedContent());
    }

    public function test_zip_compressed_raw_xml_is_extracted_on_download(): void
    {
        Storage::fake('local');

        $zipPath = Storage::disk('local')->path('dmarc-attachments/report.zip');
        Storage::disk('local')->makeDirectory('dmarc-attachments');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('report.xml', '<feedback>zipped</feedback>');
        $zip->close();

        $user = User::factory()->create();
        $report = AggregateReport::factory()->create([
            'raw_xml_path' => 'dmarc-attachments/report.zip',
        ]);

        $response = $this->actingAs($user)->get(route('reports.download', $report));

        $response->assertOk();
        $this->assertEquals('<feedback>zipped</feedback>', $response->streamedContent());
    }

    public function test_download_returns_404_when_raw_xml_is_missing(): void
    {
        $user = User::factory()->create();
        $report = AggregateReport::factory()->create(['raw_xml_path' => null]);

        $this->actingAs($user)
            ->get(route('reports.download', $report))
            ->assertNotFound();
    }
}
