<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLogger
{
    private const AUDITABLE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const AUTH_PATHS = ['login', 'register', 'logout', 'me'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->method(), self::AUDITABLE_METHODS, true) || $this->isAuthEvent($request)) {
            $this->log($request, $response);
        }

        return $response;
    }

    private function isAuthEvent(Request $request): bool
    {
        $path = $request->path();

        foreach (self::AUTH_PATHS as $authPath) {
            if (str_ends_with($path, $authPath)) {
                return true;
            }
        }

        return false;
    }

    private function log(Request $request, Response $response): void
    {
        $user = $request->user();

        AuditLog::withoutGlobalScope('tenant')->create([
            'tenant_id' => $user?->tenant_id,
            'user_id' => $user?->id,
            'event' => 'api_request',
            'description' => $request->method() . ' ' . $request->path(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'status_code' => $response->getStatusCode(),
            ],
        ]);
    }

    public static function logSecurityViolation(Request $request, string $description, array $extra = []): void
    {
        $user = $request->user();

        AuditLog::withoutGlobalScope('tenant')->create([
            'tenant_id' => $user?->tenant_id,
            'user_id' => $user?->id,
            'event' => 'security_violation',
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => array_merge([
                'method' => $request->method(),
                'path' => $request->path(),
            ], $extra),
        ]);
    }
}
