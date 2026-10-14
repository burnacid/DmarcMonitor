<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\UserInvitation;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Password;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

#[Fillable(['name', 'email', 'password', 'role', 'all_organisations'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
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
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'all_organisations' => 'boolean',
        ];
    }

    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        return $this->two_factor_recovery_codes ?? [];
    }

    /**
     * Mails the user a link to choose their own password. Any earlier
     * invitation link stops working.
     */
    public function sendInvitation(): void
    {
        $this->notify(new UserInvitation(Password::broker('invites')->createToken($this)));
    }

    /**
     * Invited users stay unverified until they've set a password from the
     * invitation link.
     */
    public function isInvitePending(): bool
    {
        return $this->email_verified_at === null;
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
     * Whether this user is restricted to specific organisations. Access to
     * every organisation has to be granted explicitly (all_organisations), so
     * a restricted user without organisations sees nothing rather than
     * everything.
     */
    public function hasOrganisationScope(): bool
    {
        return ! $this->all_organisations;
    }

    /**
     * The organisation IDs this user is restricted to (possibly none), or
     * null if the user has access to all organisations.
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
