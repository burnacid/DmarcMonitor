<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'organisation_id', 'action', 'subject_type', 'subject_id',
        'description', 'context', 'ip_address',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $organisationIds = $user->scopedOrganisationIds();

        if ($organisationIds === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($organisationIds) {
            $q->whereIn('organisation_id', $organisationIds)->orWhereNull('organisation_id');
        });
    }
}
