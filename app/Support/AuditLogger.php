<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public static function record(
        string $action,
        string $description,
        ?Model $subject = null,
        ?int $organisationId = null,
        ?int $userId = -1,
        ?array $context = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId === -1 ? auth()->id() : $userId,
            'organisation_id' => $organisationId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'context' => $context,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * The dirty attributes left on $model after a save(), for use as the
     * context on an "updated" audit entry. Excludes noise that isn't a
     * meaningful part of the change (timestamps, hashed secrets).
     *
     * @return array<string, mixed>
     */
    public static function describeChanges(Model $model): array
    {
        $changes = $model->getChanges();

        unset($changes['created_at'], $changes['updated_at']);

        foreach (array_keys($changes) as $key) {
            if (preg_match('/password|secret|token/i', $key)) {
                unset($changes[$key]);
            }
        }

        return $changes;
    }
}
