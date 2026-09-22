<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_security_headers_are_present_on_every_response(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');
        $response->assertHeader('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $user = User::factory()->create();

        $plain = $this->actingAs($user)->get('/dashboard');
        $plain->assertHeaderMissing('Strict-Transport-Security');

        $secure = $this->actingAs($user)->get('https://localhost/dashboard');
        $secure->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_the_csp_nonce_on_the_inline_theme_script_matches_the_header(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $matches);

        $this->assertNotEmpty($matches, 'Expected a nonce in the Content-Security-Policy header.');
        $response->assertSee('nonce="'.$matches[1].'"', false);
    }

    public function test_the_csp_is_skipped_in_local_development_since_vite_dev_assets_are_cross_origin(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }
}
