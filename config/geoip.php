<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GeoLite2 ASN database path
    |--------------------------------------------------------------------------
    |
    | Path to the MaxMind GeoLite2-ASN.mmdb file used to resolve sending IPs
    | to their owning network/organisation. Download it with a free MaxMind
    | account (https://www.maxmind.com/en/geolite2/signup) and keep it fresh
    | with their `geoipupdate` tool (weekly cron recommended). When the file
    | is missing, ASN enrichment is skipped gracefully — only reverse DNS runs.
    |
    */
    'mmdb_path' => env('MAXMIND_ASN_DB_PATH', storage_path('app/geoip/GeoLite2-ASN.mmdb')),

    /*
    |--------------------------------------------------------------------------
    | MaxMind license key
    |--------------------------------------------------------------------------
    |
    | Not used directly by the app (the database is refreshed by MaxMind's own
    | `geoipupdate` tool, configured separately), but surfaced in the admin
    | status page so it's obvious whether one is configured.
    |
    */
    'license_key' => env('MAXMIND_LICENSE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Cache lifetime
    |--------------------------------------------------------------------------
    |
    | How long a resolved (or failed) IP lookup is trusted before it's looked
    | up again — PTR/ASN ownership does change over time.
    |
    */
    'cache_days' => env('GEOIP_CACHE_DAYS', 30),
];
