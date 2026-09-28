<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Dns\DomainAuthenticationChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DomainOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(DomainAuthenticationChecker::class, new DomainAuthenticationChecker(fn () => ['v=DMARC1; p=none', 'v=spf1 -all']));
    }

    public function test_bulk_add_creates_new_domains_and_reports_what_it_skipped(): void
    {
        $user = User::factory()->create();
        $org = Organisation::factory()->create();
        Domain::factory()->create(['fqdn' => 'Existing.test']);
        Domain::factory()->create(['fqdn' => 'binned.test'])->delete();

        $component = Volt::actingAs($user)->test('admin.domains')
            ->call('openBulk')
            ->set('bulkDomains', "https://New-One.test/path\n*.second.test., existing.test binned.test\nnot_a_domain new-one.test")
            ->set('bulkOrganisationId', $org->id)
            ->call('saveBulk')
            ->assertHasNoErrors();

        $this->assertSame([
            'added' => ['new-one.test', 'second.test'],
            'existing' => ['existing.test'],
            'trashed' => ['binned.test'],
            'invalid' => ['not_a_domain'],
        ], $component->get('bulkResult'));

        $added = Domain::where('fqdn', 'new-one.test')->first();
        $this->assertSame($org->id, $added->organisation_id);
        $this->assertSame('weak', $added->dmarc_status);
        $this->assertSame(2, AuditLog::where('action', 'domain.created')->count());
    }

    public function test_scoped_user_cannot_bulk_add_to_another_organisation(): void
    {
        $own = Organisation::factory()->create();
        $other = Organisation::factory()->create();
        $user = User::factory()->create();
        $user->organisations()->attach($own->id);

        Volt::actingAs($user)->test('admin.domains')
            ->set('bulkDomains', 'client.test')
            ->set('bulkOrganisationId', $other->id)
            ->call('saveBulk')
            ->assertHasErrors(['bulkOrganisationId']);

        $this->assertDatabaseMissing('domains', ['fqdn' => 'client.test']);
    }

    public function test_add_domains_link_opens_bulk_modal_for_that_organisation(): void
    {
        $user = User::factory()->create();
        $org = Organisation::factory()->create();

        Livewire::withQueryParams(['organisation' => (string) $org->id, 'bulk' => '1'])
            ->actingAs($user)
            ->test('admin.domains')
            ->assertSet('openBulkOnLoad', true)
            ->assertSet('bulkOrganisationId', $org->id);
    }

    public function test_generator_keeps_existing_report_addresses_and_adds_this_apps(): void
    {
        config(['dmarc.rua_address' => 'dmarc@msp.test']);
        $user = User::factory()->create();
        $domain = Domain::factory()->create([
            'fqdn' => 'client.test',
            'dmarc_record' => 'v=DMARC1; p=none; rua=mailto:reports@vendor.test; fo=1',
        ]);

        $component = Volt::actingAs($user)->test('admin.domains')
            ->call('openGenerator', $domain->id, 'quarantine');

        $this->assertSame(
            'v=DMARC1; p=quarantine; rua=mailto:reports@vendor.test,mailto:dmarc@msp.test; fo=1',
            $component->instance()->generatedRecord(),
        );
        $this->assertSame(
            ['client.test._report._dmarc.vendor.test', 'client.test._report._dmarc.msp.test'],
            $component->instance()->generatorAuthorizationHosts(),
        );
    }

    public function test_needs_attention_filter_only_lists_domains_with_issues(): void
    {
        $user = User::factory()->create();
        Domain::factory()->create(['fqdn' => 'broken.test', 'dmarc_status' => 'missing']);
        Domain::factory()->create(['fqdn' => 'fine.test', 'created_at' => now()]);

        Volt::actingAs($user)->test('admin.domains')
            ->set('needsAttention', true)
            ->assertSee('broken.test')
            ->assertDontSee('fine.test');
    }
}
