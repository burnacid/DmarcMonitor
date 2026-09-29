<?php

namespace App\Models;

use App\Support\DmarcRecord;
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
        'dmarc_status', 'dmarc_record', 'dmarc_report_authorizations', 'spf_status', 'spf_record',
        'dkim_status', 'dkim_selector', 'dkim_record', 'dkim_selectors', 'dns_checked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'dmarc_report_authorizations' => 'array',
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

    /**
     * @return array<string, string>
     */
    public function dmarcTags(): array
    {
        return DmarcRecord::parse($this->dmarc_record);
    }

    /**
     * The published DMARC policy (none, quarantine or reject), or null
     * without a usable DMARC record.
     */
    public function dmarcPolicy(): ?string
    {
        $policy = $this->dmarcTags()['p'] ?? null;

        return in_array($policy, ['none', 'quarantine', 'reject'], true) ? $policy : null;
    }

    /**
     * Whether the DMARC record sends aggregate reports to this tool's
     * mailbox; null when that address isn't configured.
     */
    public function reportsToThisTool(): ?bool
    {
        $ruaAddress = config('dmarc.rua_address');

        if (! $ruaAddress) {
            return null;
        }

        return in_array(strtolower($ruaAddress), DmarcRecord::addresses($this->dmarcTags()['rua'] ?? null), true);
    }

    /**
     * The "v=DMARC1" records other domains must publish before providers
     * send this domain's reports there (RFC 7489 §7.1), as found at the last
     * DNS check. in_record is false for this app's own report address when
     * the domain's DMARC record doesn't list it yet.
     *
     * @return list<array{report_domain: string, host: string, authorized: bool, in_record: bool}>
     */
    public function reportAuthorizations(): array
    {
        return $this->dmarc_report_authorizations ?? [];
    }

    /**
     * @return list<array{report_domain: string, host: string, authorized: bool, in_record: bool}>
     */
    public function missingReportAuthorizations(): array
    {
        return array_values(array_filter($this->reportAuthorizations(), fn (array $authorization) => ! $authorization['authorized']));
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
