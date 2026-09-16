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
        $user->organisations()->attach($orgA->id);

        $this->actingAs($user)->get(route('reports.show', $reportB))->assertNotFound();
        $this->actingAs($user)->get(route('reports.download', $reportB))->assertNotFound();
    }

    public function test_scoped_editor_cannot_create_a_domain_under_an_inaccessible_organisation(): void
    {
        $orgA = Organisation::factory()->create(['name' => 'Org A']);
        $orgB = Organisation::factory()->create(['name' => 'Org B']);

        $editor = User::factory()->editor()->create();
        $editor->organisations()->attach($orgA->id);

        Volt::actingAs($editor)->test('admin.domains')
            ->call('create')
            ->set('fqdn', 'new.example.com')
            ->set('organisation_id', $orgB->id)
            ->call('save')
            ->assertHasErrors(['organisation_id']);

        $this->assertNull(Domain::firstWhere('fqdn', 'new.example.com'));
    }

    public function test_admin_can_assign_and_unassign_organisations_to_a_user(): void
    {
        $admin = User::factory()->create();
        $orgA = Organisation::factory()->create(['name' => 'Org A']);
        $target = User::factory()->viewer()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $target->id)
            ->set('organisation_ids', [$orgA->id])
            ->call('save');

        $this->assertEquals([$orgA->id], $target->fresh()->organisations()->pluck('organisations.id')->all());
        $this->assertTrue($target->fresh()->hasOrganisationScope());

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $target->id)
            ->set('organisation_ids', [])
            ->call('save');

        $this->assertFalse($target->fresh()->hasOrganisationScope());
    }
}
