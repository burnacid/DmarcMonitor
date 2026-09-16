<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('imap:poll')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('graph-mail:poll')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('dmarc:evaluate-alerts')->everyFifteenMinutes()->withoutOverlapping();

// MaxMind publishes new GeoLite2 builds roughly twice a week; checking weekly
// (their own geoipupdate tool's default cadence) keeps ASN/country lookups
// current without hammering the download endpoint. No-ops without a license key.
Schedule::command('dmarc:update-geoip')->weekly()->withoutOverlapping();

// There's no persistent `queue:work` process configured for this app (no
// supervisor/systemd unit), so background jobs — IP enrichment dispatched
// per report, alert emails — would otherwise pile up in the `jobs` table
// forever. Draining it on the same cron-driven scheduler is the lightweight
// alternative; if a real queue worker is set up later, this becomes redundant
// (an empty queue makes it a no-op) and can be removed.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('dmarc:cleanup')->daily()->withoutOverlapping();

// DNS records change rarely; daily keeps the cached DMARC/SPF/DKIM status on
// the domains page fresh without hammering resolvers. The per-domain
// "Recheck" button on that page covers the "I just fixed my DNS" case.
Schedule::command('dmarc:check-dns')->daily()->withoutOverlapping();
