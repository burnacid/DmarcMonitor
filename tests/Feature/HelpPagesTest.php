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
}
