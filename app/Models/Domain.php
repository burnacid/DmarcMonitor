<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    /** @use HasFactory<\Database\Factories\DomainFactory> */
    use HasFactory;

    protected $fillable = ['organisation_id', 'fqdn', 'is_active', 'notes'];

    protected $casts = [
        'is_active' => 'boolean',
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
