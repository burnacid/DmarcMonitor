<?php

namespace App\Models;

use Database\Factories\AggregateReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AggregateReport extends Model
{
    /** @use HasFactory<AggregateReportFactory> */
    use HasFactory;

    protected $fillable = [
        'domain_id', 'imap_account_id', 'microsoft365_mail_account_id', 'report_id', 'org_name', 'email',
        'date_range_begin', 'date_range_end', 'policy_domain', 'policy_adkim',
        'policy_aspf', 'policy_p', 'policy_sp', 'policy_pct', 'raw_xml_path',
        'message_uid', 'processed_at',
    ];

    protected $casts = [
        'date_range_begin' => 'datetime',
        'date_range_end' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function imapAccount()
    {
        return $this->belongsTo(ImapAccount::class);
    }

    public function microsoft365MailAccount()
    {
        return $this->belongsTo(Microsoft365MailAccount::class);
    }

    public function records()
    {
        return $this->hasMany(AggregateReportRecord::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('domain', fn (Builder $q) => $q->visibleTo($user));
    }

    /**
     * The reports list's filter set, shared with its CSV export so both stay
     * in lockstep instead of drifting apart. Recognised keys: domain_id, ip
     * (comma-separated), envelope, from, to, search, spf_result, dkim_result,
     * disposition — every key is optional.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['domain_id'] ?? null, fn (Builder $q, $v) => $q->where('domain_id', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('date_range_begin', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('date_range_begin', '<=', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where(
                fn (Builder $q2) => $q2->where('org_name', 'like', "%{$v}%")
                    ->orWhere('report_id', 'like', "%{$v}%")
            ))
            ->when($filters['ip'] ?? null, function (Builder $q, $v) {
                $ips = collect(explode(',', $v))->map(fn ($ip) => trim($ip))->filter()->all();

                $q->whereHas('records', fn (Builder $q2) => $q2->whereIn('source_ip', $ips));
            })
            ->when($filters['envelope'] ?? null, fn (Builder $q, $v) => $q->whereHas('records', fn (Builder $q2) => $q2
                ->where('envelope_from', 'like', "%{$v}%")
                ->orWhere('envelope_to', 'like', "%{$v}%")
            ))
            ->when(($filters['spf_result'] ?? null) || ($filters['dkim_result'] ?? null) || ($filters['disposition'] ?? null), fn (Builder $q) => $q->whereHas('records', fn (Builder $q2) => $q2
                ->when($filters['spf_result'] ?? null, fn (Builder $q3, $v) => $q3->where('spf_result', $v))
                ->when($filters['dkim_result'] ?? null, fn (Builder $q3, $v) => $q3->where('dkim_result', $v))
                ->when($filters['disposition'] ?? null, fn (Builder $q3, $v) => $q3->where('disposition', $v))
            ));
    }
}
