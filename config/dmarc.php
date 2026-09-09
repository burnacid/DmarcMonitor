<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    |
    | How many days of aggregate report data (and resolved alert events) to
    | keep before the `dmarc:cleanup` command prunes them. Measured from each
    | report's date_range_begin (not when it was ingested) for reports, and
    | from resolved_at for alert events. Set to null to disable cleanup.
    |
    */
    'retention_days' => env('DATA_RETENTION_DAYS', 400),
];
