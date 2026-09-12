<?php

namespace App\Models;

use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    protected $fillable = [
        'organisation_id', 'fqdn', 'is_active', 'notes',
        'dmarc_status', 'dmarc_record', 'spf_status', 'spf_record',
        'dkim_status', 'dkim_selector', 'dkim_record', 'dns_checked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'dns_checked_at' => 'datetime',
    ];

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function imapAccounts()
    {
        return $this->belongsToMany(ImapAccount::class, 'imap_account_domain');
    }

    public function aggregateReports()
    {
        return $this->hasMany(AggregateReport::class);
    }

    public function forensicReports()
    {
        return $this->hasMany(ForensicReport::class);
    }

    public function alertRules()
    {
        return $this->hasMany(AlertRule::class);
    }
}
