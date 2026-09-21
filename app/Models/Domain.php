<?php

namespace App\Models;

use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'fqdn', 'is_active', 'notes',
        'dmarc_status', 'dmarc_record', 'spf_status', 'spf_record',
        'dkim_status', 'dkim_selector', 'dkim_record', 'dkim_selectors', 'dns_checked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'dkim_selectors' => 'array',
        'dns_checked_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Every DKIM selector whose DNS record existed at the last check. Falls back
     * to the single selector stored before all of them were tracked.
     *
     * @return list<array{selector: string, record: ?string}>
     */
    public function configuredDkimSelectors(): array
    {
        if ($this->dkim_selectors !== null) {
            return $this->dkim_selectors;
        }

        if ($this->dkim_status === 'valid' && $this->dkim_selector) {
            return [['selector' => $this->dkim_selector, 'record' => $this->dkim_record]];
        }

        return [];
    }

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

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $organisationIds = $user->scopedOrganisationIds();

        if ($organisationIds === null) {
            return $query;
        }

        return $query->whereIn('organisation_id', $organisationIds);
    }
}
