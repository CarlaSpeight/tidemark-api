<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CreatorController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\PostHistoryController;
use App\Http\Controllers\Api\PrescoreController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\SocialConnectionController;
use App\Http\Controllers\Api\WebhookController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — prefix: /api/v1
|--------------------------------------------------------------------------
*/

// ── Auth (public, rate-limited) ──────────────────────────────
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::middleware('throttle:forgot_password')->group(function () {
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// ── OAuth callbacks (public — called by platform redirect) ───
Route::get('/social/connect/{platform}/callback', [SocialConnectionController::class, 'callback']);

// ── Webhook ingestion (HMAC-authenticated, no Sanctum) ───────
Route::post('/webhooks/{tenant}/comments', [WebhookController::class, 'comments'])
    ->middleware('throttle:api');

// ── Authenticated routes ─────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Comments (standard rate limit)
    Route::middleware('throttle:api')->group(function () {
        Route::get('/comments', [CommentController::class, 'index']);
        Route::get('/comments/{comment}', [CommentController::class, 'show']);
        Route::post('/comments', [CommentController::class, 'store']);
    });

    // Moderation (strict rate limit: 10/min)
    Route::middleware('throttle:moderation')->group(function () {
        Route::post('/comments/{comment}/moderate', [CommentController::class, 'moderate']);
    });

    // Moderation system
    Route::middleware('throttle:moderation')->prefix('moderation')->group(function () {
        Route::get('/queue', [ModerationController::class, 'queue']);
        Route::get('/hidden-library', [ModerationController::class, 'hiddenLibrary']);
        Route::get('/stats', [ModerationController::class, 'stats']);
        Route::post('/comments/{comment}/delete', [ModerationController::class, 'delete']);
        Route::post('/comments/{comment}/hide', [ModerationController::class, 'hide']);
        Route::post('/comments/{comment}/approve', [ModerationController::class, 'approve']);
        Route::post('/comments/{comment}/restore', [ModerationController::class, 'restore']);
        Route::post('/comments/{comment}/confirm-delete', [ModerationController::class, 'confirmDelete']);
        Route::post('/comments/{comment}/ban-user', [ModerationController::class, 'banUser']);
    });

    // Social Connections
    Route::middleware('throttle:api')->prefix('social')->group(function () {
        Route::get('/connections', [SocialConnectionController::class, 'index']);
        Route::get('/connect/{platform}/redirect', [SocialConnectionController::class, 'redirect']);
        Route::delete('/connections/{connection}', [SocialConnectionController::class, 'destroy']);
        Route::post('/website/webhook-secret/regenerate', [SocialConnectionController::class, 'regenerateWebhookSecret']);
    });

    // ── Admin session management ─────────────────────────────
    Route::prefix('admin')->group(function () {
        Route::get('/platform-stats', [AdminController::class, 'platformStats']);
        Route::get('/sessions', [AuthController::class, 'sessions']);
        Route::delete('/users/{user}/sessions', [AuthController::class, 'revokeSessions']);
    });

    // ── Risk Check / Prescore ────────────────────────────────
    Route::middleware('throttle:prescore')->prefix('risk-check')->group(function () {
        Route::post('/analyse', [PrescoreController::class, 'analyse']);
        Route::get('/batch-status/{batchId}', [PrescoreController::class, 'batchStatus']);
    });

    // ── Post History ─────────────────────────────────────────
    Route::middleware('throttle:api')->prefix('post-history')->group(function () {
        Route::get('/', [PostHistoryController::class, 'index']);
        Route::post('/analyse-batch', [PostHistoryController::class, 'analyseBatch']);
    });

    // ── Reports ──────────────────────────────────────────────
    Route::middleware('throttle:api')->prefix('reports')->group(function () {
        Route::get('/journalist/dashboard', [ReportsController::class, 'journalistDashboard']);
        Route::get('/editor/dashboard', [ReportsController::class, 'editorDashboard']);
        Route::get('/senior/dashboard', [ReportsController::class, 'seniorDashboard']);
        Route::get('/creator/dashboard', [ReportsController::class, 'creatorDashboard']);
        Route::post('/monthly-export', [ReportsController::class, 'monthlyExport']);
    });

    // ── Engagement ───────────────────────────────────────────
    Route::middleware('throttle:engagement')->prefix('engagement')->group(function () {
        Route::get('/queue', [EngagementController::class, 'queue']);
        Route::post('/drafts/{draft}/approve', [EngagementController::class, 'approve']);
        Route::post('/drafts/{draft}/edit-and-approve', [EngagementController::class, 'editAndApprove']);
        Route::post('/drafts/{draft}/reject', [EngagementController::class, 'reject']);
        Route::get('/tone-profiles', [EngagementController::class, 'getToneProfile']);
        Route::post('/tone-profiles', [EngagementController::class, 'storeToneProfile']);
        Route::get('/performance', [EngagementController::class, 'performance']);
        Route::get('/posted', [EngagementController::class, 'posted']);
        Route::get('/auto-rules', [EngagementController::class, 'indexAutoRules']);
        Route::post('/auto-rules', [EngagementController::class, 'storeAutoRule']);
        Route::patch('/auto-rules/{autoRule}', [EngagementController::class, 'updateAutoRule']);
        Route::delete('/auto-rules/{autoRule}', [EngagementController::class, 'destroyAutoRule']);
        Route::post('/pause', [EngagementController::class, 'pause']);
        Route::post('/resume', [EngagementController::class, 'resume']);
    });

    // ── Creator ───────────────────────────────────────────────
    Route::middleware('throttle:api')->prefix('creator')->group(function () {
        Route::get('/profile', [CreatorController::class, 'profile']);
        Route::put('/profile', [CreatorController::class, 'updateProfile']);
        Route::post('/surge-events/{event}/step-away', [CreatorController::class, 'stepAway']);
        Route::get('/community-highlights', [CreatorController::class, 'communityHighlights']);
        Route::post('/community-highlights/{highlight}/pin', [CreatorController::class, 'pinHighlight']);
        Route::post('/community-highlights/{highlight}/save', [CreatorController::class, 'saveHighlight']);
        Route::get('/weekly-digest', [CreatorController::class, 'weeklyDigest']);
    });
});

// ── Demo Login Shortcut (only available when APP_ENV=demo) ───
if (app()->environment('demo')) {
    Route::get('/demo/switch/{email}', function (string $email) {
        $user = User::where('email', $email)->firstOrFail();
        $token = $user->createToken('demo-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'tenant_id' => $user->tenant_id,
            ],
        ]);
    })->middleware('throttle:auth');
}
