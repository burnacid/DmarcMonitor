<?php

namespace Tests\Unit;

use App\Mail\AlertTriggered;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Services\Alerts\AlertNotifier;
use App\Support\OutboundUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function event(AlertRule $rule, Domain $domain): AlertEvent
    {
        return AlertEvent::create([
            'alert_rule_id' => $rule->id,
            'domain_id' => $domain->id,
            'fired_at' => now(),
            'dedup_key' => 'test-dedup-key',
        ]);
    }

    public function test_an_in_app_only_rule_sends_no_email_or_webhook(): void
    {
        Mail::fake();
        Http::fake();

        $domain = Domain::factory()->create();
        $rule = AlertRule::factory()->create([
            'organisation_id' => $domain->organisation_id,
            'channels' => ['in_app'],
            'notify_emails' => null,
            'webhook_url' => null,
        ]);
        $event = $this->event($rule, $domain);

        app(AlertNotifier::class)->notify($event, $rule);

        Mail::assertNothingQueued();
        Http::assertNothingSent();
        $this->assertEquals(['in_app'], $event->fresh()->notified_channels);
    }

    public function test_in_app_can_be_combined_with_email(): void
    {
        Mail::fake();

        $domain = Domain::factory()->create();
        $rule = AlertRule::factory()->create([
            'organisation_id' => $domain->organisation_id,
            'channels' => ['in_app', 'email'],
            'notify_emails' => ['ops@example.com'],
        ]);
        $event = $this->event($rule, $domain);

        app(AlertNotifier::class)->notify($event, $rule);

        Mail::assertQueued(AlertTriggered::class);
        $this->assertEquals(['in_app', 'email'], $event->fresh()->notified_channels);
    }

    public function test_a_webhook_whose_host_now_resolves_to_a_private_address_is_not_sent(): void
    {
        Http::fake();
        $this->app->instance(OutboundUrl::class, new OutboundUrl(fn (string $host) => ['10.0.0.5']));

        $domain = Domain::factory()->create();
        $rule = AlertRule::factory()->create([
            'organisation_id' => $domain->organisation_id,
            'channels' => ['webhook'],
            'notify_emails' => null,
            'webhook_url' => 'https://hooks.example.com/dmarc',
        ]);
        $event = $this->event($rule, $domain);

        app(AlertNotifier::class)->notify($event, $rule);

        Http::assertNothingSent();
        $this->assertEquals([], $event->fresh()->notified_channels);
    }

    public function test_a_webhook_redirect_is_not_followed(): void
    {
        Http::fake([
            'https://hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response('metadata'),
        ]);

        $domain = Domain::factory()->create();
        $rule = AlertRule::factory()->create([
            'organisation_id' => $domain->organisation_id,
            'channels' => ['webhook'],
            'notify_emails' => null,
            'webhook_url' => 'https://hooks.example.com/dmarc',
        ]);
        $event = $this->event($rule, $domain);

        app(AlertNotifier::class)->notify($event, $rule);

        Http::assertSentCount(1);
        $this->assertEquals([], $event->fresh()->notified_channels);
    }
}
