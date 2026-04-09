<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModerationActionRequest;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\BaseResource;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(
        private ModerationService $moderationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Comment::class);

        $comments = Comment::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('platform'), fn ($q) => $q->where('platform', $request->input('platform')))
            ->when($request->filled('min_toxicity'), fn ($q) => $q->where('toxicity_score', '>=', $request->integer('min_toxicity')))
            ->with(['article.journalist', 'moderationActions' => fn ($q) => $q->latest()->limit(1)])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return CommentResource::collection($comments)
            ->response();
    }

    public function show(Comment $comment): JsonResponse
    {
        $this->authorize('view', $comment);

        $comment->load(['article.journalist', 'moderationActions.moderator']);

        return (new CommentResource($comment))
            ->response();
    }

    public function store(StoreCommentRequest $request): JsonResponse
    {
        $this->authorize('create', Comment::class);

        $comment = Comment::create($request->validated());

        return (new CommentResource($comment))
            ->response()
            ->setStatusCode(201);
    }

    public function moderate(ModerationActionRequest $request, Comment $comment): JsonResponse
    {
        $this->authorize('moderate', $comment);

        $action = $request->validated('action');
        $user = $request->user();

        match ($action) {
            'hide' => $this->moderationService->hideComment($comment, $user),
            'approve' => $this->moderationService->approveComment($comment, $user),
            'restore' => $this->moderationService->restoreComment($comment, $user),
            'confirm_remove', 'confirm_delete' => $this->moderationService->confirmDeleteComment($comment, $user),
            'delete' => $this->moderationService->deleteComment($comment, $user),
        };

        return BaseResource::success(new CommentResource($comment->fresh()));
    }
}
