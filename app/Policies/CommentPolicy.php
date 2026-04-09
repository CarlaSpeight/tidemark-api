<?php

namespace App\Policies;

use App\Http\Middleware\AuditLogger;
use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['journalist', 'section_editor', 'senior_editor', 'admin', 'creator', 'creator_manager']);
    }

    public function view(User $user, Comment $comment): bool
    {
        return $this->checkTenantAndRole($user, $comment, 'comment access');
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['section_editor', 'senior_editor', 'admin', 'creator_manager']);
    }

    public function moderate(User $user, Comment $comment): bool
    {
        return $this->checkTenantAndScope($user, $comment, 'moderation');
    }

    public function hide(User $user, Comment $comment): bool
    {
        return $this->checkTenantAndScope($user, $comment, 'hide');
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $this->checkTenantAndScope($user, $comment, 'delete');
    }

    public function approve(User $user, Comment $comment): bool
    {
        return $this->checkTenantAndScope($user, $comment, 'approve');
    }

    public function restore(User $user, Comment $comment): bool
    {
        return $this->checkTenantAndScope($user, $comment, 'restore');
    }

    public function confirmDelete(User $user, Comment $comment): bool
    {
        if (! $this->checkTenantAndScope($user, $comment, 'confirm_delete')) {
            return false;
        }

        return $user->hasAnyRole(['section_editor', 'senior_editor', 'admin']);
    }

    public function banUser(User $user, Comment $comment): bool
    {
        if (! $this->checkTenantAndScope($user, $comment, 'ban_user')) {
            return false;
        }

        return $user->hasAnyRole(['section_editor', 'senior_editor', 'admin']);
    }

    public function viewHiddenLibrary(User $user): bool
    {
        return $user->hasAnyRole(['section_editor', 'senior_editor', 'admin']);
    }

    // ── Helpers ──

    private function checkTenantAndRole(User $user, Comment $comment, string $action): bool
    {
        if ($user->tenant_id !== $comment->tenant_id) {
            $this->logCrossTenantViolation($user, $comment, $action);

            return false;
        }

        return $user->hasAnyRole(['journalist', 'section_editor', 'senior_editor', 'admin', 'creator', 'creator_manager']);
    }

    private function checkTenantAndScope(User $user, Comment $comment, string $action): bool
    {
        if ($user->tenant_id !== $comment->tenant_id) {
            $this->logCrossTenantViolation($user, $comment, $action);

            return false;
        }

        return match ($user->role) {
            'journalist' => $comment->article && $comment->article->journalist_id === $user->id,
            'section_editor' => $comment->article && $comment->article->journalist && $comment->article->journalist->section === $user->section,
            'senior_editor', 'admin' => true,
            default => false,
        };
    }

    private function logCrossTenantViolation(User $user, Comment $comment, string $action): void
    {
        AuditLogger::logSecurityViolation(request(), "Policy: cross-tenant {$action} by user {$user->id}", [
            'resource_type' => 'comment',
            'resource_id' => $comment->id,
            'target_tenant_id' => $comment->tenant_id,
        ]);
    }
}
