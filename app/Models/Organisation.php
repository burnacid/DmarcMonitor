<?php

namespace App\Models;

use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'notes'];

    public function domains()
    {
        return $this->hasMany(Domain::class);
    }

    public function alertRules()
    {
        return $this->hasMany(AlertRule::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $organisationIds = $user->scopedOrganisationIds();

        if ($organisationIds === null) {
            return $query;
        }

        return $query->whereIn('id', $organisationIds);
    }
}
