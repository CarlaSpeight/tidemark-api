<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BaseResource;
use App\Models\Tenant;
use App\Services\Website\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private WebhookService $webhookService)
    {
    }

    public function comments(Request $request, Tenant $tenant): JsonResponse
    {
        $this->webhookService->handleIncoming($request, $tenant);

        return BaseResource::success(['received' => true]);
    }
}
