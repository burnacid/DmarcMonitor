<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use App\Services\Dns\DomainAuthenticationChecker;
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

        $fake = new DomainAuthenticationChecker(fn () => ['v=DMARC1; p=reject']);
        $this->app->instance(DomainAuthenticationChecker::class, $fake);

        Volt::actingAs($user)->test('admin.domains')
            ->call('checkDns', $domain->id);

        $this->assertEquals('valid', $domain->fresh()->dmarc_status);
        $this->assertNotNull($domain->fresh()->dns_checked_at);
    }
}
