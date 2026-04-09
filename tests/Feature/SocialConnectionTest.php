<?php

namespace Tests\Feature;

use App\Models\SocialConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialConnectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $seniorEditor;

    private User $journalist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Test Publisher',
            'slug' => 'test-publisher',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');

        $this->seniorEditor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'senior_editor',
            'is_active' => true,
        ]);
        $this->seniorEditor->assignRole('senior_editor');

        $this->journalist = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'journalist',
            'is_active' => true,
        ]);
        $this->journalist->assignRole('journalist');
    }

    private function createConnection(array $overrides = []): SocialConnection
    {
        return SocialConnection::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'platform' => 'facebook',
            'platform_page_id' => 'page_123',
            'platform_page_name' => 'Test Page',
            'access_token' => 'test-token',
            'refresh_token' => 'test-refresh',
            'token_expires_at' => now()->addDays(30),
            'is_active' => true,
            'needs_reauth' => false,
        ], $overrides));
    }

    // ── GET /social/connections ──────────────────────────────

    public function test_admin_can_list_connections(): void
    {
        $this->createConnection(['platform' => 'facebook']);
        $this->createConnection(['platform' => 'twitter']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connections');

        $response->assertOk();

        $data = $response->json('data');

        // 2 real connections + 1 virtual Substack entry
        $this->assertCount(3, $data);

        $substack = collect($data)->firstWhere('platform', 'substack');
        $this->assertNotNull($substack);
        $this->assertStringContains('Substack does not provide a comment moderation API', $substack['note']);
        $this->assertStringContains('test-publisher', $substack['dashboard_url']);
    }

    public function test_senior_editor_can_list_connections(): void
    {
        $response = $this->actingAs($this->seniorEditor, 'sanctum')
            ->getJson('/api/v1/social/connections');

        $response->assertOk();
    }

    public function test_journalist_cannot_list_connections(): void
    {
        $response = $this->actingAs($this->journalist, 'sanctum')
            ->getJson('/api/v1/social/connections');

        $response->assertStatus(403);
    }

    public function test_substack_entry_has_dashboard_url_no_connect(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connections');

        $substack = collect($response->json('data'))->firstWhere('platform', 'substack');

        $this->assertEquals('https://test-publisher.substack.com/publish/posts', $substack['dashboard_url']);
        $this->assertNull($substack['id']); // virtual — no real record
    }

    // ── GET /social/connect/{platform}/redirect ──────────────

    public function test_redirect_generates_oauth_url_for_valid_platform(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connect/facebook/redirect');

        $response->assertOk();
        $this->assertStringContains('graph.facebook.com', $response->json('data.url'));
    }

    public function test_redirect_includes_state_in_redis(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connect/twitter/redirect');

        // At least one oauth_state key should exist in cache
        // We can't easily inspect Cache for the exact key, but we can verify
        // the URL contains a state parameter
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connect/twitter/redirect');

        $url = $response->json('data.url');
        $this->assertStringContains('state=', $url);
    }

    public function test_redirect_rejects_substack(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connect/substack/redirect');

        $response->assertStatus(422);
    }

    public function test_redirect_rejects_invalid_platform(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connect/mastodon/redirect');

        $response->assertStatus(422);
    }

    public function test_twitter_redirect_uses_pkce(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connect/twitter/redirect');

        $url = $response->json('data.url');
        $this->assertStringContains('code_challenge=', $url);
        $this->assertStringContains('code_challenge_method=S256', $url);
    }

    public function test_youtube_redirect_includes_offline_access(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connect/youtube/redirect');

        $url = $response->json('data.url');
        $this->assertStringContains('access_type=offline', $url);
    }

    // ── GET /social/connect/{platform}/callback ──────────────

    public function test_callback_rejects_missing_state(): void
    {
        $response = $this->get('/api/v1/social/connect/facebook/callback?code=abc');

        $response->assertRedirect();
        $this->assertStringContains('error=missing_params', $response->headers->get('Location'));
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $response = $this->get('/api/v1/social/connect/facebook/callback?state=bogus&code=abc');

        $response->assertRedirect();
        $this->assertStringContains('error=connection_failed', $response->headers->get('Location'));
    }

    public function test_callback_exchanges_code_and_creates_connection(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 5184000,
                'id' => 'fb_page_456',
                'name' => 'My FB Page',
            ]),
        ]);

        // Seed a valid state in cache
        $state = 'valid-state-token';
        Cache::put("oauth_state:{$state}", [
            'tenant_id' => $this->tenant->id,
            'platform' => 'facebook',
        ], now()->addMinutes(5));

        $response = $this->get("/api/v1/social/connect/facebook/callback?state={$state}&code=auth-code-123");

        $response->assertRedirect();
        $this->assertStringContains('connected=facebook', $response->headers->get('Location'));

        $this->assertDatabaseHas('social_connections', [
            'tenant_id' => $this->tenant->id,
            'platform' => 'facebook',
            'platform_page_id' => 'fb_page_456',
            'is_active' => true,
            'needs_reauth' => false,
        ]);

        // State should be consumed (pulled from cache)
        $this->assertNull(Cache::get("oauth_state:{$state}"));
    }

    // ── DELETE /social/connections/{connection} ──────────────

    public function test_admin_can_delete_connection(): void
    {
        Http::fake(); // Revocation calls

        $connection = $this->createConnection();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/social/connections/{$connection->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('social_connections', ['id' => $connection->id]);
    }

    public function test_senior_editor_can_delete_connection(): void
    {
        Http::fake();

        $connection = $this->createConnection();

        $response = $this->actingAs($this->seniorEditor, 'sanctum')
            ->deleteJson("/api/v1/social/connections/{$connection->id}");

        $response->assertOk();
    }

    public function test_journalist_cannot_delete_connection(): void
    {
        $connection = $this->createConnection();

        $response = $this->actingAs($this->journalist, 'sanctum')
            ->deleteJson("/api/v1/social/connections/{$connection->id}");

        $response->assertStatus(403);
    }

    public function test_cannot_delete_cross_tenant_connection(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other',
            'slug' => 'other',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $connection = SocialConnection::withoutGlobalScope('tenant')->create([
            'tenant_id' => $otherTenant->id,
            'platform' => 'facebook',
            'platform_page_id' => 'page_999',
            'platform_page_name' => 'Other Page',
            'access_token' => 'other-token',
            'is_active' => true,
            'needs_reauth' => false,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/social/connections/{$connection->id}");

        // Either 403 (explicit check) or 404 (tenant scope)
        $this->assertTrue(in_array($response->status(), [403, 404], true));
    }

    // ── CheckTokenExpiry command ─────────────────────────────

    public function test_check_token_expiry_refreshes_expiring_tokens(): void
    {
        Http::fake([
            '*' => Http::response([
                'access_token' => 'refreshed-token',
                'refresh_token' => 'refreshed-refresh',
                'expires_in' => 5184000,
            ]),
        ]);

        $connection = $this->createConnection([
            'token_expires_at' => now()->addDays(3), // Expiring within 7 days
        ]);

        $this->artisan('social:check-token-expiry')
            ->assertSuccessful();

        $connection->refresh();
        $this->assertFalse($connection->needs_reauth);
    }

    public function test_check_token_expiry_flags_failure(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $connection = $this->createConnection([
            'token_expires_at' => now()->addDays(3),
        ]);

        $this->artisan('social:check-token-expiry')
            ->assertSuccessful();

        $connection->refresh();
        $this->assertTrue($connection->needs_reauth);
    }

    public function test_check_token_expiry_skips_substack(): void
    {
        $connection = $this->createConnection([
            'platform' => 'substack',
            'token_expires_at' => now()->addDays(3),
        ]);

        $this->artisan('social:check-token-expiry')
            ->assertSuccessful();

        $connection->refresh();
        $this->assertFalse($connection->needs_reauth);
    }

    public function test_check_token_expiry_skips_healthy_tokens(): void
    {
        Http::fake();

        $this->createConnection([
            'token_expires_at' => now()->addDays(30), // Not expiring soon
        ]);

        $this->artisan('social:check-token-expiry')
            ->assertSuccessful();

        // No HTTP calls should have been made
        Http::assertNothingSent();
    }

    // ── Enhanced index fields ────────────────────────────────

    public function test_connections_include_comment_counts_and_status(): void
    {
        $conn = $this->createConnection(['platform' => 'facebook', 'last_polled_at' => now()->subMinutes(10)]);

        $article = \App\Models\Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'journalist_id' => $this->admin->id,
            'title' => 'Test',
            'url' => 'https://example.com/test',
            'platform' => 'facebook',
            'published_at' => now(),
        ]);

        \App\Models\Comment::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'article_id' => $article->id,
            'platform' => 'facebook',
            'original_text' => 'Comment 1',
            'commenter_platform_id' => 'usr_1',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connections');

        $response->assertOk();

        $fb = collect($response->json('data'))->firstWhere('platform', 'facebook');
        $this->assertEquals(1, $fb['total_comments_processed']);
        $this->assertNotNull($fb['last_sync_time']);
        $this->assertEquals('active', $fb['connection_status']);
    }

    public function test_instagram_connection_includes_deletion_note(): void
    {
        $this->createConnection(['platform' => 'instagram', 'platform_page_id' => 'ig_123']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connections');

        $ig = collect($response->json('data'))->firstWhere('platform', 'instagram');
        $this->assertStringContains('permanently deleted', $ig['note']);
    }

    public function test_tiktok_connection_includes_deletion_note(): void
    {
        $this->createConnection(['platform' => 'tiktok', 'platform_page_id' => 'tt_123']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connections');

        $tt = collect($response->json('data'))->firstWhere('platform', 'tiktok');
        $this->assertStringContains('permanently deleted', $tt['note']);
    }

    public function test_connection_status_shows_needs_reauth(): void
    {
        $this->createConnection(['needs_reauth' => true]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connections');

        $fb = collect($response->json('data'))->firstWhere('platform', 'facebook');
        $this->assertEquals('needs_reauth', $fb['connection_status']);
    }

    public function test_connection_status_shows_token_expired(): void
    {
        $this->createConnection(['token_expires_at' => now()->subDay()]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/social/connections');

        $fb = collect($response->json('data'))->firstWhere('platform', 'facebook');
        $this->assertEquals('token_expired', $fb['connection_status']);
    }

    // ── POST /social/website/webhook-secret/regenerate ───────

    public function test_admin_can_regenerate_webhook_secret(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/social/website/webhook-secret/regenerate');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['webhook_secret', 'message']]);

        $secret = $response->json('data.webhook_secret');
        $this->assertEquals(64, strlen($secret));

        $this->tenant->refresh();
        $this->assertEquals($secret, $this->tenant->settings['webhook_secret']);
    }

    public function test_webhook_regeneration_creates_audit_log(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/social/website/webhook-secret/regenerate');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'webhook_secret_regenerated',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_non_admin_cannot_regenerate_webhook_secret(): void
    {
        $response = $this->actingAs($this->seniorEditor, 'sanctum')
            ->postJson('/api/v1/social/website/webhook-secret/regenerate');

        $response->assertStatus(403);
    }

    public function test_journalist_cannot_regenerate_webhook_secret(): void
    {
        $response = $this->actingAs($this->journalist, 'sanctum')
            ->postJson('/api/v1/social/website/webhook-secret/regenerate');

        $response->assertStatus(403);
    }

    // ── Helper ───────────────────────────────────────────────

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'.",
        );
    }
}
