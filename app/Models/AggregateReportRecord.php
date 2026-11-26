<?php

namespace App\Models;

use Database\Factories\AggregateReportRecordFactory;
use Illuminate\Database\Eloquent\Builder;
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
     * Records the user may see that match the Reports page filters, judged
     * on the record itself: unlike AggregateReport::filter(), which keeps a
     * whole report when any one of its records matches. Domain and dates
     * still apply to the record's report. The search looks through the
     * record's sender details and its report's organisation and ID.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters, User $user): Builder
    {
        $reportFilters = array_intersect_key($filters, array_flip(['domain_id', 'from', 'to']));

        return $query
            ->whereIn('aggregate_report_records.aggregate_report_id', AggregateReport::query()->visibleTo($user)->filter($reportFilters)->select('aggregate_reports.id'))
            ->when($filters['ip'] ?? null, fn (Builder $q, $v) => $q->whereIn(
                'aggregate_report_records.source_ip',
                collect(explode(',', $v))->map(fn ($ip) => trim($ip))->filter()->all(),
            ))
            ->when($filters['envelope'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $q2) => $q2
                ->where('aggregate_report_records.envelope_from', 'like', "%{$v}%")
                ->orWhere('aggregate_report_records.envelope_to', 'like', "%{$v}%")
            ))
            ->when($filters['spf_result'] ?? null, fn (Builder $q, $v) => $q->where('aggregate_report_records.spf_result', $v))
            ->when($filters['dkim_result'] ?? null, fn (Builder $q, $v) => $q->where('aggregate_report_records.dkim_result', $v))
            ->when($filters['disposition'] ?? null, fn (Builder $q, $v) => $q->where('aggregate_report_records.disposition', $v))
            ->when(trim((string) ($filters['search'] ?? '')), fn (Builder $q, string $v) => $q->where(function (Builder $q2) use ($v) {
                foreach (['source_ip', 'ptr_hostname', 'asn_org', 'header_from', 'envelope_from', 'envelope_to', 'dkim_domain', 'spf_domain'] as $column) {
                    $q2->orWhere("aggregate_report_records.{$column}", 'like', "%{$v}%");
                }

                if (preg_match('/^(?:AS)?(\d+)$/i', $v, $asn)) {
                    $q2->orWhere('aggregate_report_records.asn', (int) $asn[1]);
                }

                $q2->orWhereIn('aggregate_report_records.aggregate_report_id', AggregateReport::query()
                    ->where(fn (Builder $q3) => $q3->where('org_name', 'like', "%{$v}%")->orWhere('report_id', 'like', "%{$v}%"))
                    ->select('id'));
            }));
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
