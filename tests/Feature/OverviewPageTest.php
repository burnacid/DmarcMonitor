<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OverviewPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewers_can_open_the_overview(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->get('/overview')->assertOk()->assertSee('Overview');
    }

    public function test_organisations_needing_attention_are_listed_first(): void
    {
        $user = User::factory()->create();
        $healthy = Organisation::factory()->create(['name' => 'Aardvark Ltd']);
        $broken = Organisation::factory()->create(['name' => 'Zebra BV']);
        Domain::factory()->create(['organisation_id' => $healthy->id, 'created_at' => now()]);
        Domain::factory()->create(['organisation_id' => $broken->id, 'dmarc_status' => 'missing']);

        Volt::actingAs($user)->test('overview')
            ->assertSeeInOrder(['Zebra BV', 'Aardvark Ltd'])
            ->set('needsAttention', true)
            ->assertSee('Zebra BV')
            ->assertDontSee('Aardvark Ltd');
    }

    public function test_scoped_user_only_sees_their_organisations_and_no_unassigned_row(): void
    {
        $own = Organisation::factory()->create(['name' => 'Own Org']);
        $other = Organisation::factory()->create(['name' => 'Other Org']);
        Domain::factory()->create(['organisation_id' => $own->id]);
        Domain::factory()->create(['organisation_id' => $other->id]);
        Domain::factory()->create(['organisation_id' => null]);
        $user = User::factory()->create();
        $user->organisations()->attach($own->id);

        Volt::actingAs($user)->test('overview')
            ->assertSee('Own Org')
            ->assertDontSee('Other Org')
            ->assertDontSee('Unassigned domains');
    }

    public function test_dashboard_link_preselects_the_organisation_and_ignores_inaccessible_ones(): void
    {
        $own = Organisation::factory()->create();
        $other = Organisation::factory()->create();
        $user = User::factory()->create();
        $user->organisations()->attach($own->id);

        Livewire::withQueryParams(['organisation' => $own->id, 'days' => 90])->actingAs($user)->test('dashboard')
            ->assertSet('organisationId', $own->id)
            ->assertSet('days', 90);

        Livewire::withQueryParams(['organisation' => $other->id, 'days' => 5])->actingAs($user)->test('dashboard')
            ->assertSet('organisationId', null)
            ->assertSet('days', 30);
    }

    public function test_admins_see_unassigned_domains_and_can_expand_an_organisation(): void
    {
        $admin = User::factory()->create();
        $org = Organisation::factory()->create(['name' => 'Acme']);
        Domain::factory()->create(['organisation_id' => $org->id, 'fqdn' => 'acme-mail.test']);
        Domain::factory()->create(['organisation_id' => null]);

        Volt::actingAs($admin)->test('overview')
            ->assertSee('Unassigned domains')
            ->assertDontSee('acme-mail.test')
            ->call('toggleExpand', (string) $org->id)
            ->assertSee('acme-mail.test');
    }
}
