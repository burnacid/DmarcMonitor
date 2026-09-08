<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    /** @use HasFactory<\Database\Factories\AlertRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'domain_id', 'type', 'threshold_percent', 'lookback_window', 'channels',
        'webhook_url', 'notify_emails', 'is_active',
    ];

    protected $casts = [
        'channels' => 'array',
        'notify_emails' => 'array',
        'is_active' => 'boolean',
        'threshold_percent' => 'decimal:2',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function events()
    {
        return $this->hasMany(AlertEvent::class);
    }
}
