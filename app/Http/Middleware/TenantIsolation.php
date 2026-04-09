<?php

namespace App\Http\Middleware;

use App\Traits\HasTenant;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        foreach ($request->route()->parameters() as $parameter) {
            if ($parameter instanceof Model && $this->isTenantScoped($parameter)) {
                if ($parameter->tenant_id !== $user->tenant_id) {
                    AuditLogger::logSecurityViolation($request, 'Cross-tenant access attempt', [
                        'target_tenant_id' => $parameter->tenant_id,
                        'resource_type' => get_class($parameter),
                        'resource_id' => $parameter->getKey(),
                    ]);

                    abort(403, 'Forbidden.');
                }
            }
        }

        return $next($request);
    }

    private function isTenantScoped(Model $model): bool
    {
        return in_array(HasTenant::class, class_uses_recursive($model), true);
    }
}
