<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class BaseResource extends JsonResource
{
    public static function success($data, array $meta = [], int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta,
            'errors' => [],
        ], $statusCode);
    }

    public static function error(array $errors, int $statusCode = 400, $data = null): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [],
            'errors' => $errors,
        ], $statusCode);
    }

    public function with($request): array
    {
        return [
            'meta' => [],
            'errors' => [],
        ];
    }
}
