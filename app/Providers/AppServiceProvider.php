<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->enforceProductionSafety();
        $this->configureRateLimiting();
        $this->configurePolicies();
    }

    private function enforceProductionSafety(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        if (config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }

        if (config('app.key') === 'base64:DEFAULT_KEY_CHANGE_ME') {
            throw new RuntimeException('APP_KEY must not be the default value in production.');
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('moderation', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('prescore', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('engagement', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('forgot_password', function (Request $request) {
            return Limit::perHour(3)->by($request->ip());
        });
    }

    private function configurePolicies(): void
    {
        Gate::policy(\App\Models\Comment::class, \App\Policies\CommentPolicy::class);
    }
}
