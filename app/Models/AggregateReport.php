<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AggregateReport extends Model
{
    /** @use HasFactory<\Database\Factories\AggregateReportFactory> */
    use HasFactory;

    protected $fillable = [
        'domain_id', 'imap_account_id', 'report_id', 'org_name', 'email',
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

    public function records()
    {
        return $this->hasMany(AggregateReportRecord::class);
    }
}
