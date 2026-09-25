<?php

namespace App\Models;

use App\Models\Concerns\HasMicrosoft365Credentials;
use Database\Factories\Microsoft365SendAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Microsoft365SendAccount extends Model
{
    /** @use HasFactory<Microsoft365SendAccountFactory> */
    use HasFactory;

    use HasMicrosoft365Credentials;

    protected $fillable = [
        'label', 'tenant_id', 'client_id', 'client_secret', 'mailbox',
        'is_active', 'last_used_at', 'last_error',
    ];

    protected $hidden = ['client_secret'];

    protected $casts = [
        'client_secret' => 'encrypted',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];
}
