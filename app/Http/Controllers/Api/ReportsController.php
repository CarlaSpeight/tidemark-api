<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ArticleResource;
use App\Http\Resources\BaseResource;
use App\Http\Resources\CommunityHighlightResource;
use App\Http\Resources\SurgeEventResource;
use App\Services\ReportingService;
use App\Services\StatsCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsController
{
    public function __construct(private ReportingService $service)
    {
    }

    public function journalistDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = StatsCache::journalistDashboard($user->id, fn () => $this->service->journalistDashboard($user));

        $data['articles_today'] = ArticleResource::collection($data['articles_today'])->resolve();

        return BaseResource::success($data);
    }

    public function editorDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasAnyRole(['section_editor', 'senior_editor', 'admin'])) {
            abort(403);
        }

        $data = StatsCache::editorDashboard(
            $user->tenant_id,
            $user->section,
            fn () => $this->service->editorDashboard($user),
        );

        return BaseResource::success($data);
    }

    public function seniorDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasAnyRole(['senior_editor', 'admin'])) {
            abort(403);
        }

        $data = StatsCache::seniorDashboard(
            $user->tenant_id,
            fn () => $this->service->seniorDashboard($user),
        );

        return BaseResource::success($data);
    }

    public function creatorDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasAnyRole(['creator', 'creator_manager'])) {
            abort(403);
        }

        $data = StatsCache::creatorDashboard($user->id, fn () => $this->service->creatorDashboard($user));

        $data['community_highlights'] = CommunityHighlightResource::collection($data['community_highlights'])->resolve();
        $data['surge_status'] = $data['surge_status'] ? (new SurgeEventResource($data['surge_status']))->resolve() : null;

        return BaseResource::success($data);
    }

    public function monthlyExport(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasAnyRole(['senior_editor', 'admin'])) {
            abort(403);
        }

        $url = $this->service->monthlyExport($user);

        return BaseResource::success([
            'url' => $url,
            'expires_in' => '1 hour',
        ]);
    }
}
