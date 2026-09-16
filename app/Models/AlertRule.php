<?php

namespace App\Models;

use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'organisation_id', 'type', 'threshold_percent', 'lookback_window', 'channels',
        'webhook_url', 'notify_emails', 'is_active',
    ];

    protected $casts = [
        'channels' => 'array',
        'notify_emails' => 'array',
        'is_active' => 'boolean',
        'threshold_percent' => 'decimal:2',
    ];

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function events()
    {
        return $this->hasMany(AlertEvent::class);
    }

    /**
     * A rule with no organisation (e.g. a "new domain discovered" rule) is
     * global and stays visible to everyone; organisation-specific rules are
     * scoped.
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
}
