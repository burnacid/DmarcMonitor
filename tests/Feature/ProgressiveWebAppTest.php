<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressiveWebAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_is_served_without_login_and_carries_the_app_name(): void
    {
        config(['app.name' => 'Acme DMARC']);

        $response = $this->get('/manifest.webmanifest');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'name' => 'Acme DMARC',
                'start_url' => '/dashboard',
                'display' => 'standalone',
            ]);
    }

    public function test_every_icon_the_manifest_lists_exists_including_a_maskable_one(): void
    {
        $icons = collect($this->get('/manifest.webmanifest')->json('icons'));

        $this->assertTrue($icons->contains('purpose', 'maskable'));

        foreach ($icons as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }

        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }

    public function test_pages_link_the_manifest_and_offer_the_install_item_in_the_user_menu(): void
    {
        $this->get(route('login'))->assertSee('rel="manifest"', escape: false);

        $this->actingAs(User::factory()->create())
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('<link rel="manifest" href="'.route('web-manifest').'">', escape: false)
            ->assertSee('Install app');
    }
}
