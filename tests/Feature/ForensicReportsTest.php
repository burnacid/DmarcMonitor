<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\ForensicReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ForensicReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_forensic_reports_pages(): void
    {
        $report = ForensicReport::factory()->create();

        $this->get('/forensic-reports')->assertRedirect('/login');
        $this->get("/forensic-reports/{$report->id}")->assertRedirect('/login');
    }

    public function test_forensic_reports_index_lists_reports(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        ForensicReport::factory()->create([
            'domain_id' => $domain->id,
            'source_ip' => '203.0.113.9',
            'delivery_result' => 'reject',
        ]);

        Volt::actingAs($user)->test('forensic-reports.index')
            ->assertSee('example.com')
            ->assertSee('203.0.113.9')
            ->assertSee('reject');
    }

    public function test_forensic_reports_index_filters_by_domain(): void
    {
        $user = User::factory()->create();
        $domainA = Domain::factory()->create(['fqdn' => 'a.com']);
        $domainB = Domain::factory()->create(['fqdn' => 'b.com']);
        ForensicReport::factory()->create(['domain_id' => $domainA->id, 'source_ip' => '203.0.113.1']);
        ForensicReport::factory()->create(['domain_id' => $domainB->id, 'source_ip' => '203.0.113.2']);

        Volt::actingAs($user)->test('forensic-reports.index')
            ->set('domain_id', $domainA->id)
            ->assertSee('203.0.113.1')
            ->assertDontSee('203.0.113.2');
    }

    public function test_forensic_reports_show_displays_report_details(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['fqdn' => 'example.com']);
        $report = ForensicReport::factory()->create([
            'domain_id' => $domain->id,
            'source_ip' => '203.0.113.9',
            'delivery_result' => 'reject',
        ]);

        Volt::actingAs($user)->test('forensic-reports.show', ['forensicReport' => $report])
            ->assertSee('example.com')
            ->assertSee('203.0.113.9')
            ->assertSee('reject');
    }

    public function test_raw_message_can_be_downloaded(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('dmarc-attachments/report.eml', 'From: a@example.com');

        $user = User::factory()->create();
        $report = ForensicReport::factory()->create([
            'raw_message_path' => 'dmarc-attachments/report.eml',
        ]);

        $response = $this->actingAs($user)->get(route('forensic-reports.download', $report));

        $response->assertOk();
        $this->assertEquals('From: a@example.com', $response->streamedContent());
    }

    public function test_download_returns_404_when_raw_message_is_missing(): void
    {
        $user = User::factory()->create();
        $report = ForensicReport::factory()->create(['raw_message_path' => null]);

        $this->actingAs($user)
            ->get(route('forensic-reports.download', $report))
            ->assertNotFound();
    }
}
