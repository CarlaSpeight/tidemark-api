<?php

namespace App\Traits;

use App\Models\AuditLog;

trait Auditable
{
    protected static function audit(
        string $event,
        string $resourceType,
        int|string|null $resourceId = null,
        string $description = '',
        array $extra = [],
        ?\App\Models\User $actor = null,
    ): void {
        $user = $actor ?? auth()->user();

        AuditLog::withoutGlobalScope('tenant')->create([
            'tenant_id' => $user?->tenant_id,
            'user_id' => $user?->id,
            'event' => $event,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'properties' => array_merge([
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ], $extra),
        ]);
    }
}
