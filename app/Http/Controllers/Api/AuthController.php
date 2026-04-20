<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\BaseResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->ip(),
        );

        if ($result['status'] === 'locked') {
            return BaseResource::error(
                [['message' => 'Too many login attempts. Please try again later.']],
                429,
            );
        }

        if ($result['status'] === 'failed') {
            // Never reveal whether email or password was wrong
            return BaseResource::error(
                [['message' => 'Invalid credentials.']],
                401,
            );
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token']->plainTextToken,
                'expires_at' => $result['expires_at']->toISOString(),
            ],
            'meta' => [],
            'errors' => [],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return BaseResource::success(null);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('tenant');

        if (in_array($user->role, ['creator', 'agent'], true)) {
            $user->load('creatorProfile');
        }

        $permissions = $user->getAllPermissions()->pluck('name');

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'permissions' => $permissions,
                'tenant_name' => $user->tenant->name,
                'creator_profile' => in_array($user->role, ['creator', 'agent'], true)
                    ? new \App\Http\Resources\CreatorProfileResource($user->creatorProfile)
                    : null,
            ],
            'meta' => [],
            'errors' => [],
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->forgotPassword($request->validated('email'));

        // Always 200 — prevents user enumeration
        return BaseResource::success(null, ['message' => 'If an account exists, a reset link has been sent.']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = $this->authService->resetPassword($request->only(
            'email', 'password', 'password_confirmation', 'token',
        ));

        if ($status !== Password::PASSWORD_RESET) {
            return BaseResource::error(
                [['message' => 'Unable to reset password. The link may have expired.']],
                422,
            );
        }

        return BaseResource::success(null, ['message' => 'Password has been reset. Please log in again.']);
    }

    public function sessions(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $targetUser = User::findOrFail($request->integer('user_id'));

        if ($targetUser->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Forbidden.');
        }

        $tokens = $this->authService->activeTokens($targetUser);

        return BaseResource::success($tokens->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'last_used_at' => $t->last_used_at?->toISOString(),
            'expires_at' => $t->expires_at?->toISOString(),
            'created_at' => $t->created_at?->toISOString(),
        ]));
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        if ($user->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Forbidden.');
        }

        $count = $this->authService->revokeAllTokens($user);

        return BaseResource::success(null, ['revoked_count' => $count]);
    }

    private function authorizeAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['super_admin', 'cs_manager'], true)) {
            abort(403, 'Forbidden.');
        }
    }
}
