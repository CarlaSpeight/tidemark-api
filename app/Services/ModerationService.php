<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\SocialMedia\PlatformServiceFactory;
use App\Traits\Auditable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ModerationService
{
    use Auditable;

    public function getQueue(User $user, Request $request): LengthAwarePaginator
    {
        $query = Comment::query()
            ->forUser($user)
            ->with(['article.journalist', 'moderationActions' => fn ($q) => $q->latest()->limit(1)]);

        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }

        $statuses = $request->input('status')
            ? explode(',', $request->input('status'))
            : ['pending', 'queued'];

        $query->whereIn('status', $statuses);

        $query->orderByDesc('created_at');

        return $query->paginate(
            perPage: min((int) $request->input('per_page', 20), 100),
            page: (int) $request->input('page', 1),
        );
    }

    public function getHiddenLibrary(Request $request): LengthAwarePaginator
    {
        $query = Comment::query()
            ->where('status', 'hidden')
            ->whereIn('platform', ['facebook', 'youtube', 'website'])
            ->with(['article.journalist', 'moderationActions' => fn ($q) => $q->latest()->limit(1)]);

        if ($request->filled('platform')) {
            $platform = $request->input('platform');
            if (in_array($platform, ['facebook', 'youtube', 'website'])) {
                $query->where('platform', $platform);
            }
        }

        if ($request->filled('topic_category')) {
            $query->whereHas('article', fn ($q) => $q->where('topic_category', $request->input('topic_category')));
        }

        if ($request->filled('date_from')) {
            $query->where('hidden_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('hidden_at', '<=', $request->input('date_to'));
        }

        if ($request->has('hidden_by_ai')) {
            $query->where('hidden_by_ai', filter_var($request->input('hidden_by_ai'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('toxicity_min')) {
            $query->where('toxicity_score', '>=', (int) $request->input('toxicity_min'));
        }

        if ($request->filled('toxicity_max')) {
            $query->where('toxicity_score', '<=', (int) $request->input('toxicity_max'));
        }

        $query->orderByDesc('hidden_at');

        return $query->paginate(
            perPage: min((int) $request->input('per_page', 20), 100),
            page: (int) $request->input('page', 1),
        );
    }

    public function getStats(User $user): array
    {
        $baseQuery = Comment::query()->forUser($user);
        $today = now()->startOfDay();

        return [
            'pending_count' => (clone $baseQuery)->where('status', 'pending')->count(),
            'hidden_today' => (clone $baseQuery)->where('status', 'hidden')->where('hidden_at', '>=', $today)->count(),
            'approved_today' => (clone $baseQuery)->where('status', 'approved')->where('updated_at', '>=', $today)->count(),
            'deleted_today' => (clone $baseQuery)->where('status', 'deleted')->where('updated_at', '>=', $today)->count(),
            'restored_today' => (clone $baseQuery)->where('status', 'restored')->where('updated_at', '>=', $today)->count(),
            'confirmed_deleted_today' => (clone $baseQuery)->where('status', 'confirmed_deleted')->where('updated_at', '>=', $today)->count(),
            'auto_handle_rate' => $this->calculateAutoHandleRate($user),
            'tidemark_score' => $this->calculateTidemarkScore($user),
        ];
    }

    public function deleteComment(Comment $comment, User $user): void
    {
        $connection = $this->getConnection($comment);

        if ($connection) {
            $service = PlatformServiceFactory::make($comment->platform);
            $service->deleteComment($comment->commenter_platform_id, $connection);
        }

        $comment->update([
            'status' => 'deleted',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->createAction($comment, $user, 'delete');
        static::audit('comment_deleted', 'comment', $comment->id, "Comment deleted on {$comment->platform}");
    }

    public function hideComment(Comment $comment, User $user): void
    {
        $connection = $this->getConnection($comment);

        if ($comment->platform === 'website') {
            // Website: DB-only — mark hidden
            $comment->update([
                'status' => 'hidden',
                'hidden_at' => now(),
                'hidden_by_ai' => false,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);
        } else {
            // Facebook, YouTube: call platform API
            if ($connection) {
                $service = PlatformServiceFactory::make($comment->platform);
                $service->hideComment($comment->commenter_platform_id, $connection);
            }

            $comment->update([
                'status' => 'hidden',
                'hidden_at' => now(),
                'hidden_by_ai' => false,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);
        }

        $this->createAction($comment, $user, 'hide');
        static::audit('comment_hidden', 'comment', $comment->id, "Comment hidden on {$comment->platform}");
    }

    public function approveComment(Comment $comment, User $user): void
    {
        $comment->update([
            'status' => 'approved',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->createAction($comment, $user, 'approve');
        static::audit('comment_approved', 'comment', $comment->id, "Comment approved on {$comment->platform}");
    }

    public function restoreComment(Comment $comment, User $user): void
    {
        $connection = $this->getConnection($comment);

        if ($connection && $comment->platform !== 'website') {
            $service = PlatformServiceFactory::make($comment->platform);
            // Unhide = hide with false for FB, YouTube
            $service->hideComment($comment->commenter_platform_id, $connection);
        }

        $comment->update([
            'status' => 'restored',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->createAction($comment, $user, 'restore');
        static::audit('comment_restored', 'comment', $comment->id, "Previously hidden comment restored on {$comment->platform}");
    }

    public function confirmDeleteComment(Comment $comment, User $user): void
    {
        $connection = $this->getConnection($comment);

        if ($connection && $comment->platform !== 'website') {
            $service = PlatformServiceFactory::make($comment->platform);
            $service->deleteComment($comment->commenter_platform_id, $connection);
        }

        $comment->update([
            'status' => 'confirmed_deleted',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->createAction($comment, $user, 'confirm_delete');
        static::audit('comment_confirmed_deleted', 'comment', $comment->id, "Comment permanently deleted on {$comment->platform} — irreversible action");
    }

    public function banUser(Comment $comment, User $moderator): void
    {
        // First hide or delete the comment
        if ($comment->canBeHidden()) {
            $this->hideComment($comment, $moderator);
        } else {
            $this->deleteComment($comment, $moderator);
        }

        $this->createAction($comment, $moderator, 'ban_user');
        static::audit('user_banned', 'comment', $comment->id, "Commenter banned and comment removed on {$comment->platform}");
    }

    // ── Private helpers ──

    private function getConnection(Comment $comment): ?SocialConnection
    {
        if ($comment->platform === 'website') {
            return null;
        }

        return SocialConnection::withoutGlobalScope('tenant')
            ->where('tenant_id', $comment->tenant_id)
            ->where('platform', $comment->platform)
            ->where('is_active', true)
            ->first();
    }

    private function createAction(Comment $comment, User $user, string $action): void
    {
        ModerationAction::withoutGlobalScope('tenant')->create([
            'tenant_id' => $comment->tenant_id,
            'comment_id' => $comment->id,
            'moderator_id' => $user->id,
            'action' => $action,
        ]);
    }

    private function calculateAutoHandleRate(User $user): float
    {
        $baseQuery = Comment::query()->forUser($user);
        $total = (clone $baseQuery)->whereNotNull('routing_decision')->count();

        if ($total === 0) {
            return 0.0;
        }

        $autoHandled = (clone $baseQuery)
            ->whereIn('status', ['approved', 'hidden', 'deleted'])
            ->where('hidden_by_ai', true)
            ->orWhere(function ($q) {
                $q->whereNull('reviewed_by')->whereIn('status', ['approved', 'deleted']);
            })
            ->count();

        return round(($autoHandled / $total) * 100, 1);
    }

    private function calculateTidemarkScore(User $user): int
    {
        $baseQuery = Comment::query()->forUser($user);
        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            return 100;
        }

        $approved = (clone $baseQuery)->where('status', 'approved')->count();
        $hidden = (clone $baseQuery)->where('status', 'hidden')->count();
        $restored = (clone $baseQuery)->where('status', 'restored')->count();

        // Score: weighted combination of approval rate + restoration rate (healthy moderation)
        $healthyRate = ($approved + $restored) / $total;

        return min(100, (int) round($healthyRate * 100));
    }
}
