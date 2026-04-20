<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BaseResource;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModerationController
{
    public function __construct(private ModerationService $service)
    {
    }

    public function queue(Request $request): JsonResponse
    {
        $request->validate([
            'platform' => 'sometimes|string|in:website,facebook,instagram,twitter,youtube,tiktok,linkedin,substack',
            'status' => 'sometimes|string',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $paginator = $this->service->getQueue($request->user(), $request);

        return CommentResource::collection($paginator)->response();
    }

    public function hiddenLibrary(Request $request): JsonResponse
    {
        $this->authorizeRole($request, ['deputy_editor', 'editor', 'super_admin', 'cs_manager', 'cs_agent', 'friends_family']);

        $request->validate([
            'platform' => 'sometimes|string|in:facebook,youtube,website',
            'topic_category' => 'sometimes|string',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date',
            'hidden_by_ai' => 'sometimes|string|in:true,false,1,0',
            'toxicity_min' => 'sometimes|integer|min:0|max:100',
            'toxicity_max' => 'sometimes|integer|min:0|max:100',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $paginator = $this->service->getHiddenLibrary($request);

        return CommentResource::collection($paginator)->additional([
            'meta' => [
                'platform_note' => 'Instagram and TikTok comments are permanently deleted when flagged — they cannot be reviewed or recovered. This library contains only Facebook, YouTube and website comments hidden pending review.',
                'available_filters' => [
                    'platform' => ['facebook', 'youtube', 'website'],
                ],
            ],
        ])->response();
    }

    public function stats(Request $request): JsonResponse
    {
        $stats = $this->service->getStats($request->user());

        return BaseResource::success($stats);
    }

    public function delete(Request $request, Comment $comment): JsonResponse
    {
        $request->user()->can('delete', $comment) || abort(403);

        if (! $comment->isInstagramOrTikTok()) {
            return BaseResource::error([
                ['message' => 'Use confirm_delete for Facebook/YouTube/Website'],
            ], 422);
        }

        if ($comment->platform === 'substack') {
            return BaseResource::error([
                ['message' => 'Substack does not support comment moderation via API.'],
            ], 422);
        }

        $this->service->deleteComment($comment, $request->user());

        return BaseResource::success(new CommentResource($comment->fresh()));
    }

    public function hide(Request $request, Comment $comment): JsonResponse
    {
        $request->user()->can('hide', $comment) || abort(403);

        if ($comment->isInstagramOrTikTok()) {
            return BaseResource::error([
                ['message' => 'Instagram and TikTok do not support hiding — use the delete action instead'],
            ], 422);
        }

        if ($comment->platform === 'substack') {
            return BaseResource::error([
                ['message' => 'Substack does not support comment moderation via API.'],
            ], 422);
        }

        $this->service->hideComment($comment, $request->user());

        return BaseResource::success(new CommentResource($comment->fresh()));
    }

    public function approve(Request $request, Comment $comment): JsonResponse
    {
        $request->user()->can('approve', $comment) || abort(403);

        if ($comment->platform === 'substack') {
            return BaseResource::error([
                ['message' => 'Substack does not support comment moderation via API.'],
            ], 422);
        }

        $this->service->approveComment($comment, $request->user());

        return BaseResource::success(new CommentResource($comment->fresh()));
    }

    public function restore(Request $request, Comment $comment): JsonResponse
    {
        $request->user()->can('restore', $comment) || abort(403);

        if ($comment->status !== 'hidden') {
            return BaseResource::error([
                ['message' => 'Only hidden comments can be restored.'],
            ], 422);
        }

        if ($comment->isInstagramOrTikTok()) {
            return BaseResource::error([
                ['message' => 'Instagram and TikTok comments cannot be restored — they are permanently deleted when flagged.'],
            ], 422);
        }

        $this->service->restoreComment($comment, $request->user());

        return BaseResource::success(new CommentResource($comment->fresh()));
    }

    public function confirmDelete(Request $request, Comment $comment): JsonResponse
    {
        $request->user()->can('confirmDelete', $comment) || abort(403);

        if ($comment->status !== 'hidden') {
            return BaseResource::error([
                ['message' => 'Only hidden comments can be confirmed for deletion.'],
            ], 422);
        }

        if ($comment->isInstagramOrTikTok()) {
            return BaseResource::error([
                ['message' => 'Instagram and TikTok comments do not use confirm_delete — they are deleted immediately.'],
            ], 422);
        }

        $this->service->confirmDeleteComment($comment, $request->user());

        return BaseResource::success(new CommentResource($comment->fresh()));
    }

    public function banUser(Request $request, Comment $comment): JsonResponse
    {
        $request->user()->can('banUser', $comment) || abort(403);

        if ($comment->platform === 'substack') {
            return BaseResource::error([
                ['message' => 'Substack does not support comment moderation via API.'],
            ], 422);
        }

        $this->service->banUser($comment, $request->user());

        return BaseResource::success(new CommentResource($comment->fresh()));
    }

    private function authorizeRole(Request $request, array $roles): void
    {
        if (! $request->user()->hasAnyRole($roles)) {
            abort(403);
        }
    }
}
