<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitRequestBody
{
    private const MAX_BODY_BYTES = 102_400; // 100 KB

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)
            && $request->header('Content-Length', 0) > self::MAX_BODY_BYTES) {
            abort(413, 'Request body too large.');
        }

        return $next($request);
    }
}
