<?php

namespace App\Services\Alerts;

use App\Mail\AlertTriggered;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AlertNotifier
{
    public function notify(AlertEvent $event, AlertRule $rule): void
    {
        $channels = $rule->channels ?? [];
        $notified = [];

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
        try {
            $response = Http::timeout(5)->post($url, [
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
