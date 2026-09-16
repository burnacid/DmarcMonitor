<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\User;
use App\Services\Dns\DomainAuthenticationChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DomainDnsStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_valid_and_weak_and_missing_authentication_badges(): void
    {
        $user = User::factory()->create();
        Domain::factory()->create([
            'fqdn' => 'strong.example.com',
            'dmarc_status' => 'valid',
            'dmarc_record' => 'v=DMARC1; p=reject',
            'spf_status' => 'valid',
            'dkim_status' => 'valid',
            'dkim_selector' => 'selector1',
        ]);
        Domain::factory()->create([
            'fqdn' => 'weak.example.com',
            'dmarc_status' => 'weak',
            'spf_status' => 'missing',
            'dkim_status' => 'unknown',
        ]);

        Volt::actingAs($user)->test('admin.domains')
            ->assertSee('strong.example.com')
            ->assertSee('selector1')
            ->assertSee('p=none')
            ->assertSee('No selector seen yet');
    }

    public function test_recheck_button_reruns_the_authentication_checker_and_updates_the_domain(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['fqdn' => 'example.com', 'dmarc_status' => null]);

        $fake = new DomainAuthenticationChecker(fn () => ['v=DMARC1; p=reject']);
        $this->app->instance(DomainAuthenticationChecker::class, $fake);

        Volt::actingAs($user)->test('admin.domains')
            ->call('checkDns', $domain->id);

        $this->assertEquals('valid', $domain->fresh()->dmarc_status);
        $this->assertNotNull($domain->fresh()->dns_checked_at);
    }

    public function test_expanding_a_domain_shows_its_records_and_dkim_last_seen_date(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create([
            'fqdn' => 'expand.example.com',
            'dmarc_status' => 'valid',
            'dmarc_record' => 'v=DMARC1; p=reject',
            'spf_status' => 'valid',
            'spf_record' => 'v=spf1 include:_spf.example.com ~all',
            'dkim_status' => 'valid',
            'dkim_selector' => 'selector1',
            'dkim_record' => 'v=DKIM1; k=rsa; p=abc123',
        ]);

        $report = AggregateReport::factory()->create([
            'domain_id' => $domain->id,
            'date_range_end' => now()->subDays(2),
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'dkim_domain' => $domain->fqdn,
            'dkim_selector' => 'selector1',
        ]);

        $component = Volt::actingAs($user)->test('admin.domains');

        $component->assertDontSee('v=DKIM1; k=rsa; p=abc123');

        $component->call('toggleExpand', $domain->id)
            ->assertSee('v=DMARC1; p=reject')
            ->assertSee('v=spf1 include:_spf.example.com ~all')
            ->assertSee('v=DKIM1; k=rsa; p=abc123')
            ->assertSee($report->date_range_end->diffForHumans());

        $component->call('toggleExpand', $domain->id)
            ->assertDontSee('v=DKIM1; k=rsa; p=abc123');
    }

    public function test_expanded_domain_lists_all_dkim_selectors_seen_in_aggregate_reports(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create([
            'fqdn' => 'multi-selector.example.com',
            'dkim_status' => 'valid',
            'dkim_selector' => 'current-selector',
        ]);

        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'dkim_domain' => $domain->fqdn,
            'dkim_selector' => 'current-selector',
            'dkim_auth_result' => 'pass',
        ]);
        AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'dkim_domain' => $domain->fqdn,
            'dkim_selector' => 'old-selector',
            'dkim_auth_result' => 'fail',
        ]);

        Volt::actingAs($user)->test('admin.domains')
            ->call('toggleExpand', $domain->id)
            ->assertSee('current-selector')
            ->assertSee('old-selector')
            ->assertSee('Configured');
    }
}
