<?php

namespace App\Models;

use Database\Factories\Microsoft365AppRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The installation-wide, multi-tenant Microsoft Entra app registration used by
 * "Connect with Microsoft". Only a single row is ever stored.
 */
class Microsoft365AppRegistration extends Model
{
    /** @use HasFactory<Microsoft365AppRegistrationFactory> */
    use HasFactory;

    protected $fillable = ['client_id', 'client_secret'];

    protected $hidden = ['client_secret'];

    protected $casts = [
        'client_secret' => 'encrypted',
    ];

    public static function current(): ?self
    {
        return static::query()->oldest('id')->first();
    }
}
