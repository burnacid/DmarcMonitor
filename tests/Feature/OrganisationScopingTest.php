<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OrganisationScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unscoped_user_sees_reports_and_domains_from_every_organisation(): void
    {
        $orgA = Organisation::factory()->create(['name' => 'Org A']);
        $orgB = Organisation::factory()->create(['name' => 'Org B']);
        $domainA = Domain::factory()->create(['organisation_id' => $orgA->id, 'fqdn' => 'a.example.com']);
        $domainB = Domain::factory()->create(['organisation_id' => $orgB->id, 'fqdn' => 'b.example.com']);
        AggregateReport::factory()->create(['domain_id' => $domainA->id, 'org_name' => 'Report A']);
        AggregateReport::factory()->create(['domain_id' => $domainB->id, 'org_name' => 'Report B']);

        $user = User::factory()->create();

        Volt::actingAs($user)->test('reports.index')
            ->assertSee('Report A')
            ->assertSee('Report B')
            ->assertSee('a.example.com')
            ->assertSee('b.example.com');
    }

    public function test_scoped_user_only_sees_their_organisations_domains_and_reports(): void
    {
        $orgA = Organisation::factory()->create(['name' => 'Org A']);
        $orgB = Organisation::factory()->create(['name' => 'Org B']);
        $domainA = Domain::factory()->create(['organisation_id' => $orgA->id, 'fqdn' => 'a.example.com']);
        $domainB = Domain::factory()->create(['organisation_id' => $orgB->id, 'fqdn' => 'b.example.com']);
        AggregateReport::factory()->create(['domain_id' => $domainA->id, 'org_name' => 'Report A']);
        AggregateReport::factory()->create(['domain_id' => $domainB->id, 'org_name' => 'Report B']);

        $user = User::factory()->create();
        $user->update(['all_organisations' => false]);
        $user->organisations()->attach($orgA->id);

        Volt::actingAs($user)->test('reports.index')
            ->assertSee('Report A')
            ->assertDontSee('Report B')
            ->assertSee('a.example.com')
            ->assertDontSee('b.example.com');
    }

    public function test_scoped_user_cannot_view_or_download_a_report_from_another_organisation(): void
    {
        $orgA = Organisation::factory()->create(['name' => 'Org A']);
        $orgB = Organisation::factory()->create(['name' => 'Org B']);
        $domainA = Domain::factory()->create(['organisation_id' => $orgA->id]);
        $domainB = Domain::factory()->create(['organisation_id' => $orgB->id]);
        $reportB = AggregateReport::factory()->create(['domain_id' => $domainB->id]);

        $user = User::factory()->create();
        $user->update(['all_organisations' => false]);
        $user->organisations()->attach($orgA->id);

        $this->actingAs($user)->get(route('reports.show', $reportB))->assertNotFound();
        $this->actingAs($user)->get(route('reports.download', $reportB))->assertNotFound();
    }

    public function test_scoped_editor_cannot_create_a_domain_under_an_inaccessible_organisation(): void
    {
        $orgA = Organisation::factory()->create(['name' => 'Org A']);
        $orgB = Organisation::factory()->create(['name' => 'Org B']);

        $editor = User::factory()->editor()->create();
        $editor->update(['all_organisations' => false]);
        $editor->organisations()->attach($orgA->id);

        Volt::actingAs($editor)->test('admin.domains')
            ->call('create')
            ->set('fqdn', 'new.example.com')
            ->set('organisation_id', $orgB->id)
            ->call('save')
            ->assertHasErrors(['organisation_id']);

        $this->assertNull(Domain::firstWhere('fqdn', 'new.example.com'));
    }

    public function test_scoped_editor_cannot_leave_a_domain_unassigned(): void
    {
        $orgA = Organisation::factory()->create(['name' => 'Org A']);

        $editor = User::factory()->editor()->create();
        $editor->update(['all_organisations' => false]);
        $editor->organisations()->attach($orgA->id);

        Volt::actingAs($editor)->test('admin.domains')
            ->call('create')
            ->set('fqdn', 'new.example.com')
            ->set('organisation_id', null)
            ->call('save')
            ->assertHasErrors(['organisation_id']);

        $this->assertNull(Domain::firstWhere('fqdn', 'new.example.com'));
    }

    public function test_scoped_editor_can_create_a_domain_for_their_own_organisation(): void
    {
        $orgA = Organisation::factory()->create(['name' => 'Org A']);

        $editor = User::factory()->editor()->create();
        $editor->update(['all_organisations' => false]);
        $editor->organisations()->attach($orgA->id);

        Volt::actingAs($editor)->test('admin.domains')
            ->call('create')
            ->set('fqdn', 'new.example.com')
            ->set('organisation_id', $orgA->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('domains', ['fqdn' => 'new.example.com', 'organisation_id' => $orgA->id]);
    }

    public function test_scoped_editor_cannot_create_an_organisation(): void
    {
        $orgA = Organisation::factory()->create(['name' => 'Org A']);

        $editor = User::factory()->editor()->create();
        $editor->update(['all_organisations' => false]);
        $editor->organisations()->attach($orgA->id);

        Volt::actingAs($editor)->test('admin.organisations')
            ->call('create')
            ->assertStatus(403);
    }

    public function test_unscoped_editor_can_create_an_organisation(): void
    {
        $editor = User::factory()->editor()->create();

        Volt::actingAs($editor)->test('admin.organisations')
            ->call('create')
            ->set('name', 'New Org')
            ->call('save');

        $this->assertDatabaseHas('organisations', ['name' => 'New Org']);
    }

    public function test_admin_can_assign_and_unassign_organisations_to_a_user(): void
    {
        $admin = User::factory()->create();
        $orgA = Organisation::factory()->create(['name' => 'Org A']);
        $target = User::factory()->viewer()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $target->id)
            ->set('all_organisations', false)
            ->set('organisation_ids', [$orgA->id])
            ->call('save');

        $this->assertEquals([$orgA->id], $target->fresh()->organisations()->pluck('organisations.id')->all());
        $this->assertTrue($target->fresh()->hasOrganisationScope());

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $target->id)
            ->set('all_organisations', true)
            ->call('save');

        $this->assertFalse($target->fresh()->hasOrganisationScope());
        $this->assertCount(0, $target->fresh()->organisations);
    }

    public function test_a_restricted_user_needs_at_least_one_organisation(): void
    {
        $admin = User::factory()->create();
        Organisation::factory()->create();
        $target = User::factory()->viewer()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $target->id)
            ->set('all_organisations', false)
            ->set('organisation_ids', [])
            ->call('save')
            ->assertHasErrors(['organisation_ids']);

        $this->assertFalse($target->fresh()->hasOrganisationScope());
    }

    public function test_deleting_a_users_only_organisation_does_not_widen_their_access(): void
    {
        $orgA = Organisation::factory()->create(['name' => 'Org A']);
        $orgB = Organisation::factory()->create(['name' => 'Org B']);
        Domain::factory()->create(['organisation_id' => $orgB->id, 'fqdn' => 'b.example.com']);
        AggregateReport::factory()->create(['domain_id' => Domain::factory()->create(['organisation_id' => $orgB->id])->id, 'org_name' => 'Report B']);

        $editor = User::factory()->editor()->scopedTo($orgA)->create();

        Volt::actingAs($editor)->test('admin.organisations')->call('delete', $orgA->id);

        $editor->refresh();
        $this->assertTrue($editor->hasOrganisationScope());
        $this->assertSame([], $editor->scopedOrganisationIds());

        Volt::actingAs($editor)->test('reports.index')
            ->assertDontSee('Report B')
            ->assertDontSee('b.example.com');
        $this->actingAs($editor)->get(route('organisations.report-pdf', $orgB))->assertNotFound();
    }

    public function test_the_last_admin_with_access_to_all_organisations_keeps_it(): void
    {
        $admin = User::factory()->create();
        $orgA = Organisation::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $admin->id)
            ->set('all_organisations', false)
            ->set('organisation_ids', [$orgA->id])
            ->call('save')
            ->assertHasErrors(['all_organisations']);

        $this->assertFalse($admin->fresh()->hasOrganisationScope());
    }

    public function test_the_migration_keeps_full_access_for_existing_users_without_organisations(): void
    {
        $orgA = Organisation::factory()->create();
        $unscoped = User::factory()->create(['all_organisations' => false]);
        $scoped = User::factory()->create(['all_organisations' => false]);
        $scoped->organisations()->attach($orgA->id);

        $migration = require database_path('migrations/2026_10_14_182158_add_all_organisations_to_users_table.php');
        $migration->down();
        $migration->up();

        $this->assertFalse($unscoped->fresh()->hasOrganisationScope());
        $this->assertTrue($scoped->fresh()->hasOrganisationScope());
    }
}
