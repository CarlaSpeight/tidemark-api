<?php

namespace App\Services;

use App\Http\Middleware\AuditLogger;
use App\Models\AuditLog;
use App\Models\User;
use App\Traits\Auditable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthService
{
    use Auditable;

    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 15;

    private const TOKEN_EXPIRY_HOURS = 8;

    public function login(string $email, string $password, string $ip): array
    {
        if ($this->isLockedOut($ip)) {
            return ['status' => 'locked'];
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password) || ! $user->is_active) {
            $this->recordFailedAttempt($ip);
            self::audit('login_failed', 'user', null, description: 'Failed login attempt', extra: ['ip' => $ip]);

            return ['status' => 'failed'];
        }

        $this->clearFailedAttempts($ip);

        $expiresAt = now()->addHours(self::TOKEN_EXPIRY_HOURS);
        $token = $user->createToken('api-token', ['*'], $expiresAt);

        self::audit('login', 'user', $user->id, description: 'User logged in', actor: $user);

        return [
            'status' => 'success',
            'user' => $user,
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();

        self::audit('logout', 'user', $user->id, description: 'User logged out');
    }

    public function forgotPassword(string $email): string
    {
        $status = Password::sendResetLink(['email' => $email]);

        // Always audit, but never reveal user existence
        self::audit('forgot_password', 'user', null, description: 'Password reset requested', extra: [
            'email_hash' => hash('sha256', $email),
        ]);

        return $status;
    }

    public function resetPassword(array $data): string
    {
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();

            // Revoke all tokens — force re-login on all devices
            $user->tokens()->delete();

            self::audit('password_reset', 'user', $user->id, description: 'Password reset completed');
        });

        return $status;
    }

    public function activeTokens(User $targetUser): \Illuminate\Database\Eloquent\Collection
    {
        return $targetUser->tokens()
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();
    }

    public function revokeAllTokens(User $targetUser): int
    {
        $count = $targetUser->tokens()->count();
        $targetUser->tokens()->delete();

        self::audit('sessions_revoked', 'user', $targetUser->id, description: "All sessions revoked ({$count} tokens)");

        return $count;
    }

    // ── Login lockout ──

    private function cacheKey(string $ip): string
    {
        return 'login_attempts:' . $ip;
    }

    private function isLockedOut(string $ip): bool
    {
        $attempts = Cache::get($this->cacheKey($ip), 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            AuditLogger::logSecurityViolation(request(), 'Login lockout — too many failed attempts', [
                'ip' => $ip,
                'attempts' => $attempts,
            ]);

            return true;
        }

        return false;
    }

    private function recordFailedAttempt(string $ip): void
    {
        $key = $this->cacheKey($ip);
        $attempts = Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes(self::LOCKOUT_MINUTES));

        if ($attempts >= self::MAX_ATTEMPTS) {
            AuditLogger::logSecurityViolation(request(), 'Login lockout triggered', [
                'ip' => $ip,
                'attempts' => $attempts,
            ]);
        }
    }

    private function clearFailedAttempts(string $ip): void
    {
        Cache::forget($this->cacheKey($ip));
    }
}
