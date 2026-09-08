<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlertEvent extends Model
{
    /** @use HasFactory<\Database\Factories\AlertEventFactory> */
    use HasFactory;

    protected $fillable = [
        'alert_rule_id', 'domain_id', 'fired_at', 'details', 'dedup_key',
        'resolved_at', 'notified_channels',
    ];

    protected $casts = [
        'fired_at' => 'datetime',
        'details' => 'array',
        'resolved_at' => 'datetime',
        'notified_channels' => 'array',
    ];

    public function alertRule()
    {
        return $this->belongsTo(AlertRule::class);
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }
}
