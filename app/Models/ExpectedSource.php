<?php

namespace App\Models;

use App\Support\ExpectedSourceMatch;
use Database\Factories\ExpectedSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sending source an admin has marked as known and expected, such as a mail
 * security gateway that sends notifications with the customer's From domain.
 * Matching records are labelled with it and can be left out of alerts.
 */
class ExpectedSource extends Model
{
    /** @use HasFactory<ExpectedSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'organisation_id', 'domain_id', 'label', 'match_type', 'match_value', 'note',
        'null_sender_only', 'exclude_from_alerts', 'is_active',
    ];

    protected $casts = [
        'match_type' => ExpectedSourceMatch::class,
        'null_sender_only' => 'boolean',
        'exclude_from_alerts' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(AggregateReportRecord::class);
    }

    /**
     * Sources without an organisation apply everywhere and stay visible to
     * everyone, like global alert rules; the rest are scoped.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->hasOrganisationScope()) {
            return $query;
        }

        return $query->where(
            fn (Builder $q) => $q->whereNull('organisation_id')
                ->orWhereHas('organisation', fn (Builder $oq) => $oq->visibleTo($user))
        );
    }

    /**
     * Active sources that apply to the given domain: global ones, those of
     * its organisation and those for the domain itself.
     */
    public function scopeApplicableTo(Builder $query, Domain $domain): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('organisation_id')->orWhere('organisation_id', $domain->organisation_id))
            ->where(fn (Builder $q) => $q->whereNull('domain_id')->orWhere('domain_id', $domain->id));
    }

    public function matches(AggregateReportRecord $record): bool
    {
        if ($this->null_sender_only && ! $record->is_null_sender) {
            return false;
        }

        return $this->match_type->matches($record, $this->match_value);
    }

    /**
     * Higher is more specific: a domain source wins over an organisation
     * source, which wins over a global one.
     */
    public function specificity(): int
    {
        return ($this->domain_id !== null ? 2 : 0) + ($this->organisation_id !== null ? 1 : 0);
    }
}
