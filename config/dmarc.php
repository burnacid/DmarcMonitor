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

    /*
    |--------------------------------------------------------------------------
    | Local .eml/.msg import
    |--------------------------------------------------------------------------
    |
    | Base directory for importing DMARC reports from local .eml/.msg files via
    | `dmarc:import-mail-files`. Expected to contain an `inbox/` subfolder that's
    | scanned when the command is run with no path arguments; imported files
    | are moved into `processed/` or `failed/` siblings, which `dmarc:cleanup`
    | prunes using `retention_days` above.
    |
    */
    'eml_import_path' => env('DMARC_EML_IMPORT_PATH', storage_path('app/dmarc-eml')),
];
