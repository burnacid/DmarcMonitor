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

    public function test_reports_index_filters_by_spf_dkim_result_and_disposition(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();

        $matching = AggregateReport::factory()->create(['domain_id' => $domain->id, 'org_name' => 'Matching Org']);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $matching->id,
            'spf_result' => 'fail',
            'dkim_result' => 'fail',
            'disposition' => 'quarantine',
        ]);

        $other = AggregateReport::factory()->create(['domain_id' => $domain->id, 'org_name' => 'Other Org']);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $other->id,
            'spf_result' => 'pass',
            'dkim_result' => 'pass',
            'disposition' => 'none',
        ]);

        Volt::actingAs($user)->test('reports.index')
            ->set('spf_result', 'fail')
            ->assertSee('Matching Org')
            ->assertDontSee('Other Org');

        Volt::actingAs($user)->test('reports.index')
            ->set('dkim_result', 'fail')
            ->assertSee('Matching Org')
            ->assertDontSee('Other Org');

        Volt::actingAs($user)->test('reports.index')
            ->set('disposition', 'quarantine')
            ->assertSee('Matching Org')
            ->assertDontSee('Other Org');

        Volt::actingAs($user)->test('reports.index')
            ->set('disposition', 'reject')
            ->assertDontSee('Matching Org')
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

    public function test_reports_show_filters_records_by_spf_dkim_result_and_disposition(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);

        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '203.0.113.9',
            'spf_result' => 'fail',
            'dkim_result' => 'fail',
            'disposition' => 'reject',
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'source_ip' => '198.51.100.1',
            'spf_result' => 'pass',
            'dkim_result' => 'pass',
            'disposition' => 'none',
        ]);

        $component = Volt::actingAs($user)->test('reports.show', ['report' => $report]);

        $component->assertSee('203.0.113.9')->assertSee('198.51.100.1');

        $component->set('spf_result', 'fail')
            ->assertSee('203.0.113.9')
            ->assertDontSee('198.51.100.1');

        $component->set('spf_result', '')
            ->set('disposition', 'reject')
            ->assertSee('203.0.113.9')
            ->assertDontSee('198.51.100.1');
    }

    public function test_report_records_are_sorted_by_count_until_a_column_is_clicked(): void
    {
        $user = User::factory()->create();
        $report = AggregateReport::factory()->create();
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '10.0.0.10', 'count' => 5]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '10.0.0.9', 'count' => 50]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '192.0.2.1', 'count' => 20]);

        $component = Volt::actingAs($user)->test('reports.show', ['report' => $report])
            ->assertSeeInOrder(['10.0.0.9', '192.0.2.1', '10.0.0.10']);

        $component->call('sort', 'source_ip')
            ->assertSeeInOrder(['10.0.0.9', '10.0.0.10', '192.0.2.1']);

        $component->call('sort', 'source_ip')
            ->assertSeeInOrder(['192.0.2.1', '10.0.0.10', '10.0.0.9']);
    }

    public function test_reports_index_sorts_by_a_clicked_column_and_ignores_unknown_sort_columns(): void
    {
        $user = User::factory()->create();
        AggregateReport::factory()->create(['org_name' => 'Zeta Mail', 'date_range_begin' => now()->subDay()]);
        AggregateReport::factory()->create(['org_name' => 'Alpha Mail', 'date_range_begin' => now()->subDays(5)]);

        Volt::actingAs($user)->test('reports.index')
            ->assertSeeInOrder(['Zeta Mail', 'Alpha Mail'])
            ->call('sort', 'org_name')
            ->assertSeeInOrder(['Alpha Mail', 'Zeta Mail'])
            ->assertSet('sortDirection', 'asc');

        Volt::withQueryParams(['sort' => 'id; drop table users', 'dir' => 'sideways'])
            ->actingAs($user)
            ->test('reports.index')
            ->assertSeeInOrder(['Zeta Mail', 'Alpha Mail']);
    }

    public function test_dispositions_are_shown_as_coloured_badges(): void
    {
        $this->blade('<x-disposition-badge disposition="none" />')->assertSee('bg-green-100')->assertSee('none');
        $this->blade('<x-disposition-badge disposition="quarantine" />')->assertSee('bg-orange-100')->assertSee('quarantine');
        $this->blade('<x-disposition-badge disposition="reject" />')->assertSee('bg-red-100')->assertSee('reject');
    }

    public function test_clicking_a_record_folds_out_its_raw_xml_one_at_a_time(): void
    {
        $user = User::factory()->create();
        $report = $this->reportWithRawXml('dmarc-attachments/report.xml', $this->twoRecordXml());
        $first = AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '203.0.113.1']);
        $second = AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '198.51.100.2']);

        $component = Volt::actingAs($user)->test('reports.show', ['report' => $report])
            ->assertDontSee('<source_ip>203.0.113.1</source_ip>');

        $component->call('toggleRecord', $second->id)
            ->assertSee('<source_ip>198.51.100.2</source_ip>')
            ->assertDontSee('<source_ip>203.0.113.1</source_ip>');

        $component->call('toggleRecord', $first->id)
            ->assertSee('<source_ip>203.0.113.1</source_ip>')
            ->assertDontSee('<source_ip>198.51.100.2</source_ip>');

        $component->call('toggleRecord', $first->id)
            ->assertDontSee('<source_ip>203.0.113.1</source_ip>');
    }

    public function test_the_whole_raw_xml_can_be_viewed_on_the_page_even_when_compressed(): void
    {
        $user = User::factory()->create();
        $report = $this->reportWithRawXml('dmarc-attachments/report.xml.gz', gzencode($this->twoRecordXml()));

        Volt::actingAs($user)->test('reports.show', ['report' => $report])
            ->assertDontSee('<org_name>Acme Mail</org_name>')
            ->call('toggleRawXml')
            ->assertSee('<org_name>Acme Mail</org_name>')
            ->assertSee('<source_ip>198.51.100.2</source_ip>');
    }

    public function test_a_record_of_a_report_without_stored_xml_says_so_when_folded_out(): void
    {
        $user = User::factory()->create();
        $report = AggregateReport::factory()->create(['raw_xml_path' => null]);
        $record = AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id]);

        Volt::actingAs($user)->test('reports.show', ['report' => $report])
            ->call('toggleRecord', $record->id)
            ->assertSee('Raw XML not stored for this report.');
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

    private function reportWithRawXml(string $path, string $contents): AggregateReport
    {
        Storage::fake('local');
        Storage::disk('local')->put($path, $contents);

        return AggregateReport::factory()->create(['raw_xml_path' => $path]);
    }

    private function twoRecordXml(): string
    {
        $record = fn (string $ip) => "<record><row><source_ip>{$ip}</source_ip><count>1</count></row><identifiers><header_from>example.com</header_from></identifiers></record>";

        return '<?xml version="1.0"?><feedback><report_metadata><org_name>Acme Mail</org_name></report_metadata>'
            .$record('203.0.113.1').$record('198.51.100.2').'</feedback>';
    }
}
