<?php

namespace App\Policies;

use App\Http\Middleware\AuditLogger;
use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            'producer',
            'journalist',
            'senior_reporter',
            'deputy_editor',
            'editor',
            'creator',
            'agent',
            'super_admin',
            'cs_manager',
            'cs_agent',
            'friends_family',
        ], true);
    }

    public function view(User $user, Comment $comment): bool
    {
        return $this->checkTenantAndRole($user, $comment, 'comment access');
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['deputy_editor', 'editor', 'super_admin', 'agent'], true);
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

        return in_array($user->role, ['deputy_editor', 'editor', 'super_admin'], true);
    }

    public function banUser(User $user, Comment $comment): bool
    {
        if (! $this->checkTenantAndScope($user, $comment, 'ban_user')) {
            return false;
        }

        return in_array($user->role, ['deputy_editor', 'editor', 'super_admin'], true);
    }

    public function viewHiddenLibrary(User $user): bool
    {
        return in_array($user->role, ['deputy_editor', 'editor', 'super_admin', 'cs_manager', 'cs_agent', 'friends_family'], true);
    }

    // ── Helpers ──

    private function checkTenantAndRole(User $user, Comment $comment, string $action): bool
    {
        if (! $this->canAccessTenant($user, $comment)) {
            $this->logCrossTenantViolation($user, $comment, $action);

            return false;
        }

        return match ($user->role) {
            'super_admin', 'cs_manager', 'cs_agent', 'friends_family' => true,
            'producer', 'journalist', 'senior_reporter', 'creator' => $comment->article && $comment->article->journalist_id === $user->id,
            'deputy_editor', 'editor' => $comment->article && $comment->article->journalist && $comment->article->journalist->section === $user->section,
            'agent' => $this->agentCanAccessComment($user, $comment),
            default => false,
        };
    }

    private function checkTenantAndScope(User $user, Comment $comment, string $action): bool
    {
        if (! $this->canAccessTenant($user, $comment)) {
            $this->logCrossTenantViolation($user, $comment, $action);

            return false;
        }

        return match ($user->role) {
            'producer', 'journalist', 'senior_reporter', 'creator' => $comment->article && $comment->article->journalist_id === $user->id,
            'deputy_editor', 'editor' => $comment->article && $comment->article->journalist && $comment->article->journalist->section === $user->section,
            'agent' => $this->agentCanAccessComment($user, $comment),
            'super_admin' => true,
            'cs_manager', 'cs_agent', 'friends_family' => false,
            default => false,
        };
    }

    private function canAccessTenant(User $user, Comment $comment): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        return $user->tenant_id === $comment->tenant_id;
    }

    private function agentCanAccessComment(User $user, Comment $comment): bool
    {
        if (! $comment->article || ! $comment->article->journalist_id) {
            return false;
        }

        return User::query()
            ->whereKey($comment->article->journalist_id)
            ->whereHas('creatorProfile', fn ($q) => $q->where('manager_user_id', $user->id))
            ->exists();
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
