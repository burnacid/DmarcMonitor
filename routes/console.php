<?php

use App\Support\ScheduledTaskTracker;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

ScheduledTaskTracker::attach(
    Schedule::command('imap:poll')->everyFiveMinutes()->withoutOverlapping()->description('IMAP mailbox poll'),
    'imap-poll',
);
ScheduledTaskTracker::attach(
    Schedule::command('graph-mail:poll')->everyFiveMinutes()->withoutOverlapping()->description('Microsoft 365 mailbox poll'),
    'graph-mail-poll',
);
ScheduledTaskTracker::attach(
    Schedule::command('dmarc:import-mail-files')->everyFiveMinutes()->withoutOverlapping()->description('Import local .eml/.msg reports'),
    'import-mail-files',
);
ScheduledTaskTracker::attach(
    Schedule::command('dmarc:evaluate-alerts')->everyFifteenMinutes()->withoutOverlapping()->description('Evaluate alert rules'),
    'evaluate-alerts',
);

// MaxMind publishes new GeoLite2 builds roughly twice a week; checking weekly
// (their own geoipupdate tool's default cadence) keeps ASN/country lookups
// current without hammering the download endpoint. No-ops without a license key.
ScheduledTaskTracker::attach(
    Schedule::command('dmarc:update-geoip')->weekly()->withoutOverlapping()->description('Update GeoIP databases'),
    'update-geoip',
);

// There's no persistent `queue:work` process configured for this app (no
// supervisor/systemd unit), so background jobs — IP enrichment dispatched
// per report, alert emails — would otherwise pile up in the `jobs` table
// forever. Draining it on the same cron-driven scheduler is the lightweight
// alternative; if a real queue worker is set up later, this becomes redundant
// (an empty queue makes it a no-op) and can be removed. Not tracked/shown on
// the "Scheduled Tasks" admin screen — it's internal plumbing, not a task an
// admin would trigger by hand.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyFiveMinutes()->withoutOverlapping();

// Catch-up sweep: dispatch enrichment jobs for any report records that are
// still missing enrichment data (e.g. because the queue job failed or the
// enrichment job was not dispatched). Runs every 15 minutes so new ingestion
// is enriched quickly without hammering DNS resolvers.
ScheduledTaskTracker::attach(
    Schedule::command('dmarc:enrich-pending')->everyFifteenMinutes()->withoutOverlapping()->description('Enrich pending report records'),
    'enrich-pending',
);

ScheduledTaskTracker::attach(
    Schedule::command('dmarc:cleanup')->daily()->withoutOverlapping()->description('Prune old data'),
    'cleanup',
);

// DNS records change rarely; daily keeps the cached DMARC/SPF/DKIM status on
// the domains page fresh without hammering resolvers. The per-domain
// "Recheck" button on that page covers the "I just fixed my DNS" case.
ScheduledTaskTracker::attach(
    Schedule::command('dmarc:check-dns')->daily()->withoutOverlapping()->description('Check domain DNS records'),
    'check-dns',
);
