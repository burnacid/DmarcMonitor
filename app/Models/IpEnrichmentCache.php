<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IpEnrichmentCache extends Model
{
    /** @use HasFactory<\Database\Factories\IpEnrichmentCacheFactory> */
    use HasFactory;

    protected $table = 'ip_enrichment_cache';

    protected $fillable = [
        'ip', 'ptr_hostname', 'asn', 'asn_org', 'country', 'looked_up_at', 'lookup_failed',
    ];

    protected $casts = [
        'looked_up_at' => 'datetime',
        'lookup_failed' => 'boolean',
    ];
}
