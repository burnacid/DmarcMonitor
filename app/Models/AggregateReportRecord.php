<?php

namespace App\Models;

use Database\Factories\AggregateReportRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AggregateReportRecord extends Model
{
    /** @use HasFactory<AggregateReportRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'aggregate_report_id', 'source_ip', 'count', 'disposition', 'dkim_result',
        'spf_result', 'header_from', 'envelope_from', 'envelope_to', 'dkim_domain',
        'dkim_selector', 'dkim_auth_result', 'spf_domain', 'spf_scope', 'spf_auth_result',
        'ptr_hostname', 'asn', 'asn_org', 'country', 'enriched_at', 'is_null_sender',
        'expected_source_id',
    ];

    protected $casts = [
        'enriched_at' => 'datetime',
        'is_null_sender' => 'boolean',
    ];

    public function aggregateReport()
    {
        return $this->belongsTo(AggregateReport::class);
    }

    public function expectedSource(): BelongsTo
    {
        return $this->belongsTo(ExpectedSource::class);
    }

    /**
     * Whether a record was sent with an empty envelope sender (`<>`), as
     * bounces and automatic notifications are. Receivers then check SPF on
     * the HELO name instead, which can't align with the From domain, so
     * only DKIM can make such a message pass DMARC. A missing envelope_from
     * doesn't count: many reporters simply leave it out.
     */
    public static function isNullSenderValues(?string $envelopeFrom, ?string $spfScope): bool
    {
        return ($envelopeFrom !== null && trim($envelopeFrom) === '<>')
            || strtolower((string) $spfScope) === 'helo';
    }
}
