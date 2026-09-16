<?php

namespace Tests\Unit;

use App\Mail\AlertTriggered;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Services\Alerts\AlertNotifier;
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
}
