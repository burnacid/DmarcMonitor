<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('imap:poll')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('dmarc:evaluate-alerts')->everyFifteenMinutes()->withoutOverlapping();

// There's no persistent `queue:work` process configured for this app (no
// supervisor/systemd unit), so background jobs — IP enrichment dispatched
// per report, alert emails — would otherwise pile up in the `jobs` table
// forever. Draining it on the same cron-driven scheduler is the lightweight
// alternative; if a real queue worker is set up later, this becomes redundant
// (an empty queue makes it a no-op) and can be removed.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyFiveMinutes()->withoutOverlapping();
