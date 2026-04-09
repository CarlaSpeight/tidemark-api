<?php

namespace App\Services;

use App\Models\SocialConnection;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialConnectionService
{
    use Auditable;

    private const OAUTH_PLATFORMS = ['facebook', 'instagram', 'twitter', 'youtube', 'tiktok', 'linkedin'];

    private const PLATFORM_CONFIG = [
        'facebook' => [
            'auth_url' => 'https://graph.facebook.com/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
            'scopes' => 'pages_manage_comments,pages_read_engagement,pages_show_list',
        ],
        'instagram' => [
            'auth_url' => 'https://graph.facebook.com/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
            'scopes' => 'pages_manage_comments,pages_read_engagement,pages_show_list',
        ],
        'twitter' => [
            'auth_url' => 'https://twitter.com/i/oauth2/authorize',
            'token_url' => 'https://api.twitter.com/2/oauth2/token',
            'scopes' => 'tweet.read tweet.write users.read offline.access',
            'pkce' => true,
        ],
        'youtube' => [
            'auth_url' => 'https://accounts.google.com/o/oauth2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scopes' => 'https://www.googleapis.com/auth/youtube.force-ssl',
        ],
        'tiktok' => [
            'auth_url' => 'https://www.tiktok.com/auth/authorize',
            'token_url' => 'https://open-api.tiktok.com/oauth/access_token/',
            'scopes' => 'comment.list,comment.delete',
        ],
        'linkedin' => [
            'auth_url' => 'https://linkedin.com/oauth/v2/authorization',
            'token_url' => 'https://linkedin.com/oauth/v2/accessToken',
            'scopes' => 'r_organization_social w_organization_social',
        ],
    ];

    public function listConnections(): Collection
    {
        return SocialConnection::all();
    }

    public function isValidOAuthPlatform(string $platform): bool
    {
        return in_array($platform, self::OAUTH_PLATFORMS, true);
    }

    public function generateRedirectUrl(string $platform, string $tenantId): string
    {
        $config = self::PLATFORM_CONFIG[$platform];
        $credentials = config("services.social.{$platform}");

        $state = Str::random(40);

        Cache::put(
            "oauth_state:{$state}",
            ['tenant_id' => $tenantId, 'platform' => $platform],
            now()->addMinutes(5),
        );

        $params = [
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $this->callbackUrl($platform),
            'response_type' => 'code',
            'scope' => $config['scopes'],
            'state' => $state,
        ];

        if (!empty($config['pkce'])) {
            $codeVerifier = Str::random(128);
            $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

            Cache::put("oauth_verifier:{$state}", $codeVerifier, now()->addMinutes(5));

            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        // Platform-specific adjustments
        if ($platform === 'youtube') {
            $params['access_type'] = 'offline';
            $params['prompt'] = 'consent';
        }

        return $config['auth_url'] . '?' . http_build_query($params);
    }

    public function handleCallback(string $platform, string $state, string $code): SocialConnection
    {
        $stateData = Cache::pull("oauth_state:{$state}");

        if (! $stateData || $stateData['platform'] !== $platform) {
            abort(403, 'Invalid or expired OAuth state.');
        }

        $tokens = $this->exchangeCode($platform, $code, $state);

        $connection = SocialConnection::withoutGlobalScope('tenant')
            ->updateOrCreate(
                [
                    'tenant_id' => $stateData['tenant_id'],
                    'platform' => $platform,
                    'platform_page_id' => $tokens['page_id'] ?? $platform,
                ],
                [
                    'platform_page_name' => $tokens['page_name'] ?? $platform,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? null,
                    'token_expires_at' => $tokens['expires_at'] ?? null,
                    'is_active' => true,
                    'needs_reauth' => false,
                ],
            );

        self::audit('social_connection_created', 'social_connection', $connection->id, description: "Connected {$platform}");

        return $connection;
    }

    public function disconnect(SocialConnection $connection): void
    {
        $this->revokeTokenOnPlatform($connection);

        $platform = $connection->platform;
        $id = $connection->id;

        $connection->delete();

        self::audit('social_connection_deleted', 'social_connection', $id, description: "Disconnected {$platform}");
    }

    public function refreshToken(SocialConnection $connection): bool
    {
        if ($connection->platform === 'substack') {
            return false;
        }

        $config = self::PLATFORM_CONFIG[$connection->platform] ?? null;

        if (! $config || ! $connection->refresh_token) {
            return false;
        }

        $credentials = config("services.social.{$connection->platform}");

        try {
            $response = Http::asForm()->post($config['token_url'], [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
            ]);

            if ($response->failed()) {
                return false;
            }

            $data = $response->json();

            $connection->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $connection->getRawOriginal('refresh_token'),
                'token_expires_at' => isset($data['expires_in'])
                    ? now()->addSeconds($data['expires_in'])
                    : null,
                'needs_reauth' => false,
            ]);

            self::audit('social_token_refreshed', 'social_connection', $connection->id, description: "Refreshed {$connection->platform} token");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Private helpers ──────────────────────────────────────

    private function exchangeCode(string $platform, string $code, string $state): array
    {
        $config = self::PLATFORM_CONFIG[$platform];
        $credentials = config("services.social.{$platform}");

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->callbackUrl($platform),
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
        ];

        if (!empty($config['pkce'])) {
            $params['code_verifier'] = Cache::pull("oauth_verifier:{$state}");
        }

        $response = Http::asForm()->post($config['token_url'], $params);

        if ($response->failed()) {
            abort(502, 'Failed to exchange authorisation code with ' . $platform);
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => isset($data['expires_in'])
                ? now()->addSeconds($data['expires_in'])
                : null,
            'page_id' => $data['id'] ?? $data['user_id'] ?? $data['open_id'] ?? null,
            'page_name' => $data['name'] ?? $data['username'] ?? null,
        ];
    }

    private function revokeTokenOnPlatform(SocialConnection $connection): void
    {
        if (! $connection->access_token || $connection->platform === 'substack') {
            return;
        }

        $credentials = config("services.social.{$connection->platform}");

        try {
            match ($connection->platform) {
                'facebook', 'instagram' => Http::delete("https://graph.facebook.com/me/permissions", [
                    'access_token' => $connection->access_token,
                ]),
                'twitter' => Http::asForm()
                    ->withBasicAuth($credentials['client_id'], $credentials['client_secret'])
                    ->post('https://api.twitter.com/2/oauth2/revoke', [
                        'token' => $connection->access_token,
                    ]),
                'youtube' => Http::post('https://oauth2.googleapis.com/revoke', [
                    'token' => $connection->access_token,
                ]),
                default => null,
            };
        } catch (\Throwable) {
            // Best-effort revocation — don't block deletion
        }
    }

    private function callbackUrl(string $platform): string
    {
        return url("/api/v1/social/connect/{$platform}/callback");
    }
}
