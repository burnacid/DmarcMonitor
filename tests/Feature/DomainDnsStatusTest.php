<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\DomainRecordHistory;
use App\Models\User;
use App\Services\Dns\DomainAuthenticationChecker;
use App\Support\MailProvider;
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

        $fake = new DomainAuthenticationChecker(fn () => ['v=DMARC1; p=reject'], fn () => []);
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

    public function test_it_shows_the_detected_mail_provider_and_its_expected_dkim_selectors(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create([
            'fqdn' => 'm365.example.com',
            'dkim_status' => 'valid',
            'mail_provider' => MailProvider::Microsoft365,
            'dkim_expected_selectors' => [
                ['selector' => 'selector1', 'found' => true],
                ['selector' => 'selector2', 'found' => false],
            ],
        ]);

        Volt::actingAs($user)->test('admin.domains')
            ->assertSee('Mail hosted by Microsoft 365')
            ->assertDontSee('selector2._domainkey.m365.example.com')
            ->call('toggleExpand', $domain->id)
            ->assertSee('Expected for Microsoft 365')
            ->assertSee('selector2._domainkey.m365.example.com');
    }

    public function test_an_expected_dkim_selector_with_only_a_cname_is_shown_as_ready_to_enable(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create([
            'fqdn' => 'm365.example.com',
            'dkim_status' => 'missing',
            'mail_provider' => MailProvider::Microsoft365,
            'dkim_expected_selectors' => [
                ['selector' => 'selector1', 'found' => false, 'cname' => 'selector1-m365-example-com._domainkey.example.d-v1.dkim.mail.microsoft'],
                ['selector' => 'selector2', 'found' => false],
            ],
        ]);

        Volt::actingAs($user)->test('admin.domains')
            ->call('toggleExpand', $domain->id)
            ->assertSee('Ready to enable')
            ->assertSee('selector1-m365-example-com._domainkey.example.d-v1.dkim.mail.microsoft')
            ->assertSee('Turn on DKIM signing for this domain at Microsoft 365');
    }

    public function test_expanding_a_domain_shows_its_previous_dmarc_and_spf_records(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['dmarc_record' => 'v=DMARC1; p=reject']);
        DomainRecordHistory::factory()->create(['domain_id' => $domain->id, 'record' => 'v=DMARC1; p=quarantine']);
        DomainRecordHistory::factory()->spf()->create(['domain_id' => $domain->id, 'record' => 'v=spf1 include:old.example.com -all']);

        Volt::actingAs($user)->test('admin.domains')
            ->assertDontSee('v=DMARC1; p=quarantine')
            ->call('toggleExpand', $domain->id)
            ->assertSee('Previous records (1)')
            ->assertSee('v=DMARC1; p=quarantine')
            ->assertSee('v=spf1 include:old.example.com -all')
            ->assertSee('Until '.now()->toFormattedDateString());
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

    public function test_every_configured_dkim_selector_is_badged_and_shows_its_record(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create([
            'fqdn' => 'several.example.com',
            'dkim_status' => 'valid',
            'dkim_selector' => 'selector1',
            'dkim_record' => 'v=DKIM1; p=first',
            'dkim_selectors' => [
                ['selector' => 'selector1', 'record' => 'v=DKIM1; p=first'],
                ['selector' => 'selector2', 'record' => 'v=DKIM1; p=second'],
            ],
        ]);

        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);

        foreach (['selector1', 'selector2', 'unpublished'] as $selector) {
            AggregateReportRecord::factory()->create([
                'aggregate_report_id' => $report->id,
                'dkim_domain' => $domain->fqdn,
                'dkim_selector' => $selector,
            ]);
        }

        $component = Volt::actingAs($user)->test('admin.domains')
            ->call('toggleExpand', $domain->id)
            ->assertSee('v=DKIM1; p=first')
            ->assertSee('v=DKIM1; p=second');

        $this->assertSame(2, substr_count($component->html(), '>Configured<'));
    }
}
