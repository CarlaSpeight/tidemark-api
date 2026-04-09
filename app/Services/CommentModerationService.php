<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Platform;
use App\Repositories\CommentRepository;
use App\Repositories\ModerationActionRepository;
use App\Traits\Auditable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CommentModerationService
{
    use Auditable;

    public function __construct(
        private CommentRepository $commentRepository,
        private ModerationActionRepository $moderationActionRepository,
    ) {
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->commentRepository->getFiltered($filters, $perPage);
    }

    public function show(string $uuid): Comment
    {
        return $this->commentRepository->findByUuid($uuid);
    }

    public function hide(Comment $comment, ?int $userId, string $initiatedBy = 'human', ?string $reason = null): Comment
    {
        $platform = $comment->platform;

        if (! $platform->canHide()) {
            throw new \DomainException("Platform [{$platform->slug}] does not support hiding comments.");
        }

        return DB::transaction(function () use ($comment, $userId, $initiatedBy, $reason) {
            $statusBefore = $comment->status;
            $comment->update(['status' => 'hidden']);

            $this->moderationActionRepository->create([
                'tenant_id' => $comment->tenant_id,
                'comment_id' => $comment->id,
                'user_id' => $userId,
                'action' => 'hide',
                'initiated_by' => $initiatedBy,
                'reason' => $reason,
                'status_before' => $statusBefore,
                'status_after' => 'hidden',
            ]);

            self::audit('comment_hidden', 'comment', $comment->id, description: $reason);

            return $comment->fresh();
        });
    }

    public function approve(Comment $comment, int $userId, ?string $reason = null): Comment
    {
        return DB::transaction(function () use ($comment, $userId, $reason) {
            $statusBefore = $comment->status;
            $comment->update(['status' => 'approved']);

            $this->moderationActionRepository->create([
                'tenant_id' => $comment->tenant_id,
                'comment_id' => $comment->id,
                'user_id' => $userId,
                'action' => 'approve',
                'initiated_by' => 'human',
                'reason' => $reason,
                'status_before' => $statusBefore,
                'status_after' => 'approved',
            ]);

            self::audit('comment_approved', 'comment', $comment->id, description: $reason);

            return $comment->fresh();
        });
    }

    public function restore(Comment $comment, int $userId, ?string $reason = null): Comment
    {
        if ($comment->status !== 'hidden') {
            throw new \DomainException('Only hidden comments can be restored.');
        }

        return DB::transaction(function () use ($comment, $userId, $reason) {
            $statusBefore = $comment->status;
            $comment->update(['status' => 'restored']);

            $this->moderationActionRepository->create([
                'tenant_id' => $comment->tenant_id,
                'comment_id' => $comment->id,
                'user_id' => $userId,
                'action' => 'restore',
                'initiated_by' => 'human',
                'reason' => $reason,
                'status_before' => $statusBefore,
                'status_after' => 'restored',
            ]);

            self::audit('comment_restored', 'comment', $comment->id, description: $reason);

            return $comment->fresh();
        });
    }

    public function confirmRemove(Comment $comment, int $userId, ?string $reason = null): Comment
    {
        $platform = $comment->platform;

        if (! $platform->canDelete()) {
            throw new \DomainException("Platform [{$platform->slug}] does not support deleting comments.");
        }

        // For Instagram/TikTok: deletion is immediate from pending
        // For Facebook/YouTube/Website: must be hidden first
        $immediateDeletePlatforms = ['instagram', 'tiktok'];
        if (! in_array($platform->slug, $immediateDeletePlatforms) && $comment->status !== 'hidden') {
            throw new \DomainException('Comment must be hidden before it can be confirmed for removal.');
        }

        return DB::transaction(function () use ($comment, $userId, $reason) {
            $statusBefore = $comment->status;
            $comment->update(['status' => 'confirmed_removed']);

            $this->moderationActionRepository->create([
                'tenant_id' => $comment->tenant_id,
                'comment_id' => $comment->id,
                'user_id' => $userId,
                'action' => 'confirm_remove',
                'initiated_by' => 'human',
                'reason' => $reason,
                'status_before' => $statusBefore,
                'status_after' => 'confirmed_removed',
            ]);

            self::audit('comment_confirmed_removed', 'comment', $comment->id, description: $reason);

            return $comment->fresh();
        });
    }

    public function holdForReview(Comment $comment, ?int $userId, string $initiatedBy = 'human', ?string $reason = null): Comment
    {
        $platform = $comment->platform;

        if (! $platform->canHoldForReview()) {
            throw new \DomainException("Platform [{$platform->slug}] does not support holding for review.");
        }

        return DB::transaction(function () use ($comment, $userId, $initiatedBy, $reason) {
            $statusBefore = $comment->status;
            $comment->update(['status' => 'hidden']);

            $this->moderationActionRepository->create([
                'tenant_id' => $comment->tenant_id,
                'comment_id' => $comment->id,
                'user_id' => $userId,
                'action' => 'hold_for_review',
                'initiated_by' => $initiatedBy,
                'reason' => $reason,
                'status_before' => $statusBefore,
                'status_after' => 'hidden',
            ]);

            self::audit('comment_held_for_review', 'comment', $comment->id, description: $reason);

            return $comment->fresh();
        });
    }
}
