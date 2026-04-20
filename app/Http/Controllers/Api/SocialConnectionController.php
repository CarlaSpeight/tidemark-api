<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BaseResource;
use App\Models\Comment;
use App\Models\SocialConnection;
use App\Services\SocialConnectionService;
use App\Traits\Auditable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialConnectionController extends Controller
{
    use Auditable;

    public function __construct(private SocialConnectionService $service)
    {
    }

    /**
     * GET /social/connections
    * Editor/deputy_editor/super_admin only.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeRole($request);

        $connections = $this->service->listConnections();
        $tenantId = $request->user()->tenant_id;

        $platformNotes = [
            'instagram' => 'Comments are permanently deleted when flagged. There is no hide-for-review feature on Instagram.',
            'tiktok' => 'Comments are permanently deleted when flagged. There is no hide-for-review feature on TikTok.',
        ];

        $data = $connections->map(function (SocialConnection $c) use ($tenantId, $platformNotes) {
            $totalComments = Comment::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('platform', $c->platform)
                ->count();

            $entry = [
                'id' => $c->id,
                'platform' => $c->platform,
                'platform_page_id' => $c->platform_page_id,
                'platform_page_name' => $c->platform_page_name,
                'is_active' => $c->is_active,
                'needs_reauth' => $c->needs_reauth,
                'connection_status' => $this->connectionStatus($c),
                'total_comments_processed' => $totalComments,
                'last_sync_time' => $c->last_polled_at?->toISOString(),
            ];

            if (isset($platformNotes[$c->platform])) {
                $entry['note'] = $platformNotes[$c->platform];
            }

            return $entry;
        });

        // Append virtual Substack entry
        $tenant = $request->user()->tenant;
        $substackComments = Comment::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('platform', 'substack')
            ->count();

        $data->push([
            'id' => null,
            'platform' => 'substack',
            'platform_page_id' => null,
            'platform_page_name' => $tenant->name,
            'is_active' => true,
            'needs_reauth' => false,
            'connection_status' => 'active',
            'total_comments_processed' => $substackComments,
            'last_sync_time' => null,
            'note' => 'Substack does not provide a comment moderation API. Comment counts are visible in your reports. All moderation must be done directly in your Substack dashboard.',
            'dashboard_url' => "https://{$tenant->slug}.substack.com/publish/posts",
        ]);

        return BaseResource::success($data);
    }

    /**
     * GET /social/connect/{platform}/redirect
     */
    public function redirect(Request $request, string $platform): JsonResponse
    {
        if (! $this->service->isValidOAuthPlatform($platform)) {
            return BaseResource::error(
                [['message' => "Platform '{$platform}' does not support OAuth."]],
                422,
            );
        }

        $url = $this->service->generateRedirectUrl($platform, $request->user()->tenant_id);

        return BaseResource::success(['url' => $url]);
    }

    /**
     * GET /social/connect/{platform}/callback
     */
    public function callback(Request $request, string $platform): RedirectResponse
    {
        if (! $this->service->isValidOAuthPlatform($platform)) {
            return redirect(config('app.frontend_url', '/') . '/settings?error=invalid_platform');
        }

        $state = $request->query('state');
        $code = $request->query('code');

        if (! $state || ! $code) {
            return redirect(config('app.frontend_url', '/') . '/settings?error=missing_params');
        }

        try {
            $this->service->handleCallback($platform, $state, $code);
        } catch (\Throwable) {
            return redirect(config('app.frontend_url', '/') . '/settings?error=connection_failed');
        }

        return redirect(config('app.frontend_url', '/') . '/settings?connected=' . $platform);
    }

    /**
     * DELETE /social/connections/{connection}
    * Editor/deputy_editor/super_admin only.
     */
    public function destroy(Request $request, SocialConnection $connection): JsonResponse
    {
        $this->authorizeRole($request);

        if ($connection->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Forbidden.');
        }

        $this->service->disconnect($connection);

        return BaseResource::success(null);
    }

    private function authorizeRole(Request $request): void
    {
        $user = $request->user();

        if (! in_array($user->role, ['editor', 'deputy_editor', 'super_admin'], true)) {
            abort(403, 'Forbidden.');
        }
    }

    /**
     * POST /social/website/webhook-secret/regenerate
    * Editor or super_admin only.
     */
    public function regenerateWebhookSecret(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['editor', 'deputy_editor', 'super_admin'], true)) {
            abort(403, 'Only editors can regenerate the webhook secret.');
        }

        $newSecret = Str::random(64);

        $tenant = $request->user()->tenant;
        $settings = $tenant->settings ?? [];
        $settings['webhook_secret'] = $newSecret;
        $tenant->update(['settings' => $settings]);

        static::audit(
            'webhook_secret_regenerated',
            'tenant',
            $tenant->id,
            'Website webhook HMAC secret regenerated',
        );

        return BaseResource::success([
            'webhook_secret' => $newSecret,
            'message' => 'Webhook secret regenerated. Update your integration immediately.',
        ]);
    }

    private function connectionStatus(SocialConnection $c): string
    {
        if ($c->needs_reauth) {
            return 'needs_reauth';
        }

        if (! $c->is_active) {
            return 'disconnected';
        }

        if ($c->token_expires_at?->isPast()) {
            return 'token_expired';
        }

        return 'active';
    }
}
