<?php

namespace App\Models;

use Database\Factories\Microsoft365MailAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Microsoft365MailAccount extends Model
{
    /** @use HasFactory<Microsoft365MailAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'label', 'tenant_id', 'client_id', 'client_secret', 'mailbox',
        'folder_inbox', 'folder_processed', 'folder_failed', 'mark_as_read',
        'include_read_messages', 'delete_after_processing', 'is_active',
        'last_polled_at', 'last_error',
    ];

    protected $hidden = ['client_secret'];

    protected $casts = [
        'client_secret' => 'encrypted',
        'mark_as_read' => 'boolean',
        'include_read_messages' => 'boolean',
        'delete_after_processing' => 'boolean',
        'is_active' => 'boolean',
        'last_polled_at' => 'datetime',
    ];

    public function domains()
    {
        return $this->belongsToMany(Domain::class, 'microsoft365_mail_account_domain');
    }
}
