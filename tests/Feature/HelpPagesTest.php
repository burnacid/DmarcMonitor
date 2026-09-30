<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_help_pages(): void
    {
        $this->get('/help')->assertRedirect('/login');
    }

    public function test_any_authenticated_user_can_view_every_help_page(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($user);

        foreach ([
            'help',
            'help/organisations-and-domains',
            'help/dns-authentication',
            'help/mail-ingestion',
            'help/reports',
            'help/alerts',
            'help/dashboard',
            'help/users-and-roles',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_the_help_index_includes_a_search_box_and_searchable_index_of_topics(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($user)
            ->get('help')
            ->assertOk()
            ->assertSee('Search help topics', false)
            ->assertSee('DKIM selectors, and the', false)
            ->assertSee('#dkim-selectors', false);
    }

    public function test_the_help_covers_the_overview_attention_flags_and_two_factor_sign_in(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($user);

        $this->get('help')
            ->assertSee('#overview', false)
            ->assertSee('#needs-attention', false)
            ->assertSee('#moving-to-enforcement', false);

        $this->get('help/dashboard')->assertSee('id="overview"', false);
        $this->get('help/organisations-and-domains')->assertSee('id="needs-attention"', false)->assertSee('Report authorisation missing');
        $this->get('help/users-and-roles')->assertSee('Two-factor authentication can also be turned on');
    }
}
