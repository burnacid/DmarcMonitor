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

    /*
    |--------------------------------------------------------------------------
    | Built-in SMTP listener
    |--------------------------------------------------------------------------
    |
    | Settings for `dmarc:smtp-serve`, which accepts mail from an internal
    | relay/forwarder and imports it directly. `allowed_ips` is a comma-separated
    | list of IPs/CIDR ranges allowed to connect; when empty only loopback is.
    | An entry `spf:<domain>` (e.g. `spf:spf.protection.outlook.com` for
    | Exchange Online) is expanded from that domain's SPF record and refreshed
    | hourly.
    |
    */
    'smtp' => [
        'host' => env('DMARC_SMTP_HOST', '127.0.0.1'),
        'port' => (int) env('DMARC_SMTP_PORT', 2525),
        'max_message_bytes' => (int) env('DMARC_SMTP_MAX_MESSAGE_BYTES', 25 * 1024 * 1024),
        'allowed_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DMARC_SMTP_ALLOWED_IPS', '')),
        ))) ?: ['127.0.0.1', '::1'],
        'idle_timeout' => (int) env('DMARC_SMTP_IDLE_TIMEOUT', 60),
        'max_connections' => (int) env('DMARC_SMTP_MAX_CONNECTIONS', 20),
        // Optional STARTTLS: enabled when a certificate is set (PEM file; the key
        // may be in the same file). `required` refuses mail sent without TLS.
        'tls' => [
            'certificate' => env('DMARC_SMTP_TLS_CERT'),
            'key' => env('DMARC_SMTP_TLS_KEY'),
            'passphrase' => env('DMARC_SMTP_TLS_PASSPHRASE'),
            'required' => (bool) env('DMARC_SMTP_TLS_REQUIRED', false),
        ],
    ],
];
