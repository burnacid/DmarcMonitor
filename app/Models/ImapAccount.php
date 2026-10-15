<?php

namespace App\Models;

use App\Models\Concerns\TracksPollResults;
use Database\Factories\ImapAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImapAccount extends Model
{
    /** @use HasFactory<ImapAccountFactory> */
    use HasFactory;

    use TracksPollResults;

    protected $fillable = [
        'label', 'host', 'port', 'encryption', 'username', 'password', 'protocol',
        'folder_inbox', 'folder_processed', 'folder_failed', 'mark_as_read',
        'include_read_messages', 'delete_after_processing', 'delete_old_messages', 'is_active',
        'last_polled_at', 'last_error', 'consecutive_failures', 'failure_notified_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'encrypted',
        'mark_as_read' => 'boolean',
        'include_read_messages' => 'boolean',
        'delete_after_processing' => 'boolean',
        'delete_old_messages' => 'boolean',
        'is_active' => 'boolean',
        'last_polled_at' => 'datetime',
    ];

    public function domains()
    {
        return $this->belongsToMany(Domain::class, 'imap_account_domain');
    }

    public function mailboxTypeLabel(): string
    {
        return __('IMAP account');
    }

    public function settingsUrl(): string
    {
        return route('admin.imap-accounts');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->hasOrganisationScope()) {
            return $query;
        }

        return $query->whereHas('domains', fn (Builder $q) => $q->visibleTo($user));
    }
}
