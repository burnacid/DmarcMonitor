<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class NavigationAlertBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function openEvent(): AlertEvent
    {
        $domain = Domain::factory()->create();
        $rule = AlertRule::factory()->create(['domain_id' => $domain->id]);

        return AlertEvent::create([
            'alert_rule_id' => $rule->id,
            'domain_id' => $domain->id,
            'fired_at' => now(),
            'dedup_key' => uniqid('dedup-', true),
        ]);
    }

    public function test_no_badge_is_shown_when_there_are_no_open_alerts(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('layout.navigation')
            ->assertDontSee('99+');

        $this->assertEquals(0, AlertEvent::whereNull('resolved_at')->count());
    }

    public function test_the_badge_shows_the_open_alert_count(): void
    {
        $user = User::factory()->create();
        $this->openEvent();
        $this->openEvent();
        $resolved = $this->openEvent();
        $resolved->update(['resolved_at' => now()]);

        Volt::actingAs($user)->test('layout.navigation')
            ->assertSee('2');
    }

    public function test_a_viewer_without_manage_access_never_sees_the_badge_or_alerts_link(): void
    {
        $user = User::factory()->viewer()->create();
        $this->openEvent();

        Volt::actingAs($user)->test('layout.navigation')
            ->assertDontSee(__('Alerts'));
    }
}
