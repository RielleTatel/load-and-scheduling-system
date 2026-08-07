<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /**
     * Record an attributable, timestamped change to a domain record (SRS §6.3).
     * The actor is the authenticated user, or null for system actions (e.g. seeding).
     */
    public function log(string $action, Model $auditable, ?array $before = null, ?array $after = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => now(),
        ]);
    }
}
