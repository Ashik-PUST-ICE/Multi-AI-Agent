<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    public function __construct(private readonly AgentActivityLogger $activityLogger) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        User $owner,
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?User $actor = null,
    ): AuditLog {
        $safeMetadata = $this->activityLogger->redactValue($metadata);

        return AuditLog::query()->create([
            'tenant_id' => $owner->tenant_id,
            'user_id' => $owner->id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'metadata' => $safeMetadata,
            'created_at' => now(),
        ]);
    }
}
