<?php

namespace App\Models;

use Database\Factories\DomainRecordHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DMARC or SPF record a domain used to publish. created_at is when the
 * DNS check found it replaced (or removed), not when it was first published.
 */
class DomainRecordHistory extends Model
{
    /** @use HasFactory<DomainRecordHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const string TYPE_DMARC = 'dmarc';

    public const string TYPE_SPF = 'spf';

    protected $fillable = ['domain_id', 'type', 'record'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
