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
}
