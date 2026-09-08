<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AggregateReportRecord extends Model
{
    /** @use HasFactory<\Database\Factories\AggregateReportRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'aggregate_report_id', 'source_ip', 'count', 'disposition', 'dkim_result',
        'spf_result', 'header_from', 'envelope_from', 'envelope_to', 'dkim_domain',
        'dkim_selector', 'dkim_auth_result', 'spf_domain', 'spf_scope', 'spf_auth_result',
        'ptr_hostname', 'asn', 'asn_org', 'enriched_at',
    ];

    protected $casts = [
        'enriched_at' => 'datetime',
    ];

    public function aggregateReport()
    {
        return $this->belongsTo(AggregateReport::class);
    }
}
