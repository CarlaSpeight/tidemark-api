<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BaseResource;
use App\Http\Resources\CommunityHighlightResource;
use App\Http\Resources\CreatorProfileResource;
use App\Http\Resources\WellbeingDigestResource;
use App\Models\CommunityHighlight;
use App\Models\SurgeEvent;
use App\Services\CreatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatorController
{
    public function __construct(private CreatorService $service)
    {
    }

    public function profile(Request $request): JsonResponse
    {
        $this->authorizeCreatorAccess($request);

        $profile = $this->service->getProfile($request->user());

        return (new CreatorProfileResource($profile))->response();
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $this->authorizeCreatorAccess($request);

        $data = $request->validate([
            'vibe_shield_level' => 'sometimes|string|in:open,filtered,protected',
            'allow_body_comments' => 'sometimes|boolean',
            'allow_relationship_comments' => 'sometimes|boolean',
            'allow_success_shaming' => 'sometimes|boolean',
            'custom_protection_rules' => 'sometimes|array|max:10',
            'custom_protection_rules.*.label' => 'required_with:custom_protection_rules|string|max:80',
            'custom_protection_rules.*.description' => 'required_with:custom_protection_rules|string|max:300',
            'custom_protection_rules.*.examples' => 'sometimes|array|max:5',
            'custom_protection_rules.*.examples.*' => 'string|max:300',
            'weekly_digest_enabled' => 'sometimes|boolean',
            'surge_alert_threshold_multiplier' => 'sometimes|numeric|min:1|max:100',
            'manager_user_id' => 'sometimes|nullable|exists:users,id',
        ]);

        $profile = $this->service->updateProfile($request->user(), $data);

        return (new CreatorProfileResource($profile))->response();
    }

    public function stepAway(Request $request, SurgeEvent $event): JsonResponse
    {
        $this->authorizeCreatorAccess($request);

        $this->service->stepAway($event, $request->user());

        return BaseResource::success(['message' => 'Step-away activated. Take all the time you need.']);
    }

    public function communityHighlights(Request $request): JsonResponse
    {
        $this->authorizeCreatorAccess($request);

        $highlights = $this->service->getHighlights($request->user());

        return CommunityHighlightResource::collection($highlights)->response();
    }

    public function pinHighlight(Request $request, CommunityHighlight $highlight): JsonResponse
    {
        $this->authorizeCreatorAccess($request);

        $this->service->pinHighlight($highlight);

        return BaseResource::success(['message' => 'Highlight pinned.']);
    }

    public function saveHighlight(Request $request, CommunityHighlight $highlight): JsonResponse
    {
        $this->authorizeCreatorAccess($request);

        $this->service->saveHighlight($highlight);

        return BaseResource::success(['message' => 'Highlight saved.']);
    }

    public function weeklyDigest(Request $request): JsonResponse
    {
        $this->authorizeCreatorAccess($request);

        $digest = $this->service->getWeeklyDigest($request->user());

        if (! $digest) {
            return BaseResource::success(null);
        }

        return (new WellbeingDigestResource($digest))->response();
    }

    // ── Helpers ──────────────────────────────────────────────

    private function authorizeCreatorAccess(Request $request): void
    {
        if (! in_array($request->user()->role, ['creator', 'agent'], true)) {
            abort(403, 'Only creators and agents can access this resource.');
        }
    }
}
