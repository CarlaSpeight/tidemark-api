<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BaseResource;
use App\Http\Resources\EngagementResponseResource;
use App\Http\Resources\ToneOfVoiceProfileResource;
use App\Models\EngagementResponse;
use App\Services\EngagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngagementController
{
    public function __construct(private EngagementService $service)
    {
    }

    public function queue(Request $request): JsonResponse
    {
        $request->validate([
            'platform' => 'sometimes|string|in:website,facebook,instagram,twitter,youtube,tiktok,linkedin,substack',
            'response_type' => 'sometimes|string',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $paginator = $this->service->getQueue($request->user(), $request);

        return EngagementResponseResource::collection($paginator)->response();
    }

    public function approve(Request $request, EngagementResponse $draft): JsonResponse
    {
        $this->ensureDraftStatus($draft);

        $this->service->approve($draft, $request->user());

        return BaseResource::success(['message' => 'Draft approved and queued for posting.']);
    }

    public function editAndApprove(Request $request, EngagementResponse $draft): JsonResponse
    {
        $this->ensureDraftStatus($draft);

        $request->validate([
            'text' => 'required|string|max:500',
        ]);

        $text = $request->input('text');

        if ($error = $this->service->validateResponseText($text)) {
            return BaseResource::error([['message' => $error]], 422);
        }

        $this->service->editAndApprove($draft, $text, $request->user());

        return BaseResource::success(['message' => 'Draft edited, approved and queued for posting.']);
    }

    public function reject(Request $request, EngagementResponse $draft): JsonResponse
    {
        $this->ensureDraftStatus($draft);

        $request->validate([
            'reason' => 'sometimes|string|max:500',
        ]);

        $this->service->reject($draft, $request->user(), $request->input('reason'));

        return BaseResource::success(['message' => 'Draft rejected.']);
    }

    public function storeToneProfile(Request $request): JsonResponse
    {
        $this->authorizeRole($request, ['admin', 'senior_editor']);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'formality_level' => 'sometimes|string|in:very_formal,formal,neutral,informal,very_informal',
            'personality_traits' => 'sometimes|array',
            'topics_to_avoid' => 'sometimes|array',
            'rival_brands_to_avoid' => 'sometimes|array',
            'example_responses' => 'required|array|min:3',
            'example_responses.*' => 'string|max:500',
            'custom_instructions' => 'sometimes|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'auto_response_enabled' => 'sometimes|boolean',
            'auto_response_types' => 'sometimes|array',
        ]);

        $profile = $this->service->saveToneProfile($data, $request->user());

        return (new ToneOfVoiceProfileResource($profile))
            ->response()
            ->setStatusCode(201);
    }

    public function performance(Request $request): JsonResponse
    {
        $stats = $this->service->getPerformance($request->user());

        return BaseResource::success($stats);
    }

    public function pause(Request $request): JsonResponse
    {
        $this->service->pause($request->user());

        return BaseResource::success(['message' => 'Engagement auto-posting paused.']);
    }

    public function resume(Request $request): JsonResponse
    {
        $this->authorizeRole($request, ['admin']);

        $this->service->resume($request->user());

        return BaseResource::success(['message' => 'Engagement auto-posting resumed.']);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function ensureDraftStatus(EngagementResponse $draft): void
    {
        if ($draft->status !== 'draft') {
            abort(422, 'Only drafts can be approved, edited, or rejected.');
        }
    }

    private function authorizeRole(Request $request, array $roles): void
    {
        if (! in_array($request->user()->role, $roles)) {
            abort(403, 'Insufficient permissions.');
        }
    }
}
