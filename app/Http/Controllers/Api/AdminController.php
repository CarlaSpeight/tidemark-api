<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BaseResource;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController
{
    public function platformStats(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->role !== 'super_admin') {
            abort(403);
        }

        $today = now()->startOfDay();

        $activeTenants = Tenant::query()
            ->where('is_active', true)
            ->count();

        $totalUsers = User::query()->count();

        $commentsProcessedToday = Comment::bypassTenantScope(
            fn () => Comment::query()->where('created_at', '>=', $today)->count()
        );

        $autoHandledToday = Comment::bypassTenantScope(
            fn () => Comment::query()
                ->where('created_at', '>=', $today)
                ->whereIn('status', ['approved', 'hidden', 'confirmed_deleted', 'deleted'])
                ->whereNull('reviewed_by')
                ->count()
        );

        $autoHandleRate = $commentsProcessedToday > 0
            ? round(($autoHandledToday / $commentsProcessedToday) * 100, 1)
            : 0.0;

        $publicationTenants = Tenant::query()
            ->where('is_active', true)
            ->whereIn('product_tier', ['media', 'both'])
            ->count();

        $creatorTenants = Tenant::query()
            ->where('is_active', true)
            ->whereIn('product_tier', ['creator', 'both'])
            ->count();

        $tidemarkStaffUsers = User::query()
            ->where('user_account_type', 'tidemark_staff')
            ->count();

        $recentActivity = AuditLog::query()
            ->with('user:id,email')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description,
                'user_email' => $log->user?->email,
                'timestamp' => $log->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        return BaseResource::success([
            'active_tenants' => $activeTenants,
            'total_users' => $totalUsers,
            'comments_today' => $commentsProcessedToday,
            'auto_handle_rate' => $autoHandleRate,
            'publication_tenants' => $publicationTenants,
            'creator_tenants' => $creatorTenants,
            'tidemark_staff_users' => $tidemarkStaffUsers,
            'recent_activity' => $recentActivity,
        ]);
    }
}
