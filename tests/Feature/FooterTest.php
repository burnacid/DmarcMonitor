<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_layout_footer_links_to_github(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/domains')
            ->assertOk()
            ->assertSee('https://github.com/burnacid/DmarcMonitor', false);
    }

    public function test_footer_shows_the_application_version(): void
    {
        config(['app.version' => '9.8.7']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('v9.8.7');
    }

    public function test_footer_hides_the_version_when_it_is_unknown(): void
    {
        config(['app.version' => null]);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('/releases/tag/', false);
    }

    public function test_guest_layout_footer_links_to_github(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('https://github.com/burnacid/DmarcMonitor', false);
    }
}
