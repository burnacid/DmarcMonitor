<?php

namespace App\Services\Alerts;

use App\Mail\AlertTriggered;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Support\OutboundUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AlertNotifier
{
    public function __construct(private readonly OutboundUrl $outboundUrl) {}

    public function notify(AlertEvent $event, AlertRule $rule): void
    {
        $channels = $rule->channels ?? [];
        $notified = [];

        // "in_app" has no delivery step of its own — the AlertEvent row is
        // always created regardless of channel, which is what the alerts
        // page and nav badge already read from. Recording it here just
        // reflects the rule's own configuration back in notified_channels.
        if (in_array('in_app', $channels, true)) {
            $notified[] = 'in_app';
        }

        if (in_array('email', $channels, true) && filled($rule->notify_emails)) {
            Mail::to($rule->notify_emails)->queue(new AlertTriggered($event));
            $notified[] = 'email';
        }

        if (in_array('webhook', $channels, true) && filled($rule->webhook_url)) {
            if ($this->sendWebhook($rule->webhook_url, $event)) {
                $notified[] = 'webhook';
            }
        }

        $event->update(['notified_channels' => $notified]);
    }

    private function sendWebhook(string $url, AlertEvent $event): bool
    {
        // Checked again at send time: the host's DNS may point somewhere else
        // than when the rule was saved. Redirects aren't followed, so a public
        // URL can't bounce the request to an internal one.
        if (! $this->outboundUrl->isAllowed($url)) {
            Log::warning("Alert webhook for event [{$event->id}] was not sent: [{$url}] is not a public http(s) address.");

            return false;
        }

        try {
            $response = Http::timeout(5)->withoutRedirecting()->post($url, [
                'alert_rule_id' => $event->alert_rule_id,
                'domain_id' => $event->domain_id,
                'domain' => $event->domain->fqdn,
                'type' => $event->alertRule->type,
                'fired_at' => $event->fired_at->toIso8601String(),
                'details' => $event->details,
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning("Alert webhook delivery failed for event [{$event->id}]: {$e->getMessage()}");

            return false;
        }
    }
}
