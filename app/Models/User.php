<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEditor(): bool
    {
        return $this->role === 'editor';
    }

    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    /**
     * Whether this user can reach the configuration/admin pages (everything
     * short of user management, which is admin-only).
     */
    public function canManage(): bool
    {
        return in_array($this->role, ['admin', 'editor'], true);
    }

    public function organisations(): BelongsToMany
    {
        return $this->belongsToMany(Organisation::class);
    }

    /**
     * Whether this user is restricted to specific organisations. A user with
     * no organisations assigned is unscoped and can access everything.
     */
    public function hasOrganisationScope(): bool
    {
        return $this->organisations()->exists();
    }

    /**
     * The organisation IDs this user is restricted to, or null if the user
     * is unscoped (full access to all organisations).
     *
     * @return array<int>|null
     */
    public function scopedOrganisationIds(): ?array
    {
        if (! $this->hasOrganisationScope()) {
            return null;
        }

        return $this->organisations()->pluck('organisations.id')->all();
    }

    public function canAccessOrganisation(?int $organisationId): bool
    {
        $scopedIds = $this->scopedOrganisationIds();

        if ($scopedIds === null) {
            return true;
        }

        if ($organisationId === null) {
            return false;
        }

        return in_array($organisationId, $scopedIds, true);
    }
}
