<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AlertRuleCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_alert_pages(): void
    {
        $this->get('/admin/alert-rules')->assertRedirect('/login');
        $this->get('/admin/alert-events')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_create_a_pass_rate_drop_rule(): void
    {
        $user = User::factory()->create();
        $org = Organisation::factory()->create();

        Volt::actingAs($user)->test('admin.alert-rules')
            ->call('create')
            ->set('organisation_id', $org->id)
            ->set('type', 'pass_rate_drop')
            ->set('threshold_percent', 90)
            ->set('lookback_window', '24h')
            ->set('channels', ['email'])
            ->set('notify_emails', 'ops@example.com')
            ->call('save');

        $this->assertDatabaseHas('alert_rules', [
            'organisation_id' => $org->id,
            'type' => 'pass_rate_drop',
            'threshold_percent' => 90,
        ]);

        $rule = AlertRule::firstWhere('organisation_id', $org->id);
        $this->assertEquals(['ops@example.com'], $rule->notify_emails);
    }

    public function test_threshold_is_required_unless_the_rule_watches_for_new_sources(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('admin.alert-rules')
            ->call('create')
            ->set('type', 'pass_rate_drop')
            ->set('threshold_percent', '')
            ->set('channels', ['email'])
            ->set('notify_emails', 'ops@example.com')
            ->call('save')
            ->assertHasErrors(['threshold_percent']);
    }

    public function test_webhook_url_is_required_when_the_webhook_channel_is_enabled(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('admin.alert-rules')
            ->call('create')
            ->set('type', 'new_source_detected')
            ->set('channels', ['webhook'])
            ->set('webhook_url', '')
            ->call('save')
            ->assertHasErrors(['webhook_url']);
    }

    public function test_at_least_one_email_is_required_when_the_email_channel_is_enabled(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('admin.alert-rules')
            ->call('create')
            ->set('type', 'new_source_detected')
            ->set('channels', ['email'])
            ->set('notify_emails', '')
            ->call('save')
            ->assertHasErrors(['notify_emails']);
    }

    public function test_a_rule_can_be_configured_as_in_app_only(): void
    {
        $user = User::factory()->create();
        $org = Organisation::factory()->create();

        Volt::actingAs($user)->test('admin.alert-rules')
            ->call('create')
            ->set('organisation_id', $org->id)
            ->set('type', 'new_source_detected')
            ->set('channels', ['in_app'])
            ->call('save')
            ->assertHasNoErrors();

        $rule = AlertRule::firstWhere('organisation_id', $org->id);
        $this->assertEquals(['in_app'], $rule->channels);
    }

    public function test_new_domain_discovered_rule_is_forced_to_apply_across_all_domains(): void
    {
        $user = User::factory()->create();
        $org = Organisation::factory()->create();

        Volt::actingAs($user)->test('admin.alert-rules')
            ->call('create')
            ->set('organisation_id', $org->id)
            ->set('type', 'new_domain_discovered')
            ->set('channels', ['email'])
            ->set('notify_emails', 'ops@example.com')
            ->call('save');

        $rule = AlertRule::firstWhere('type', 'new_domain_discovered');
        $this->assertNotNull($rule);
        $this->assertNull($rule->organisation_id);
    }

    public function test_authenticated_user_can_resolve_an_open_alert_event(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();
        $rule = AlertRule::factory()->create(['organisation_id' => $domain->organisation_id]);
        $event = AlertEvent::create([
            'alert_rule_id' => $rule->id,
            'domain_id' => $domain->id,
            'fired_at' => now(),
            'dedup_key' => 'test-dedup-key',
        ]);

        Volt::actingAs($user)->test('admin.alert-events')
            ->call('resolve', $event->id);

        $this->assertNotNull($event->fresh()->resolved_at);
    }
}
