<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreatorProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'test@tidemark.media',
            'password' => 'SecureP@ss123!',
            'role' => 'journalist',
            'is_active' => true,
        ]);
        $this->user->assignRole('journalist');
    }

    // ── Login ────────────────────────────────────────────────

    public function test_successful_login(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@tidemark.media',
            'password' => 'SecureP@ss123!',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['user', 'token', 'expires_at'],
                'meta',
                'errors',
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotNull($response->json('data.expires_at'));

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'event' => 'login',
        ]);
    }

    public function test_failed_login_wrong_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@tidemark.media',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'errors' => [['message' => 'Invalid credentials.']],
            ]);

        // Verify audit log for failed attempt
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'login_failed',
        ]);
    }

    public function test_failed_login_wrong_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@tidemark.media',
            'password' => 'SecureP@ss123!',
        ]);

        // Same error message as wrong password — no user enumeration
        $response->assertStatus(401)
            ->assertJson([
                'errors' => [['message' => 'Invalid credentials.']],
            ]);
    }

    public function test_failed_login_inactive_user(): void
    {
        $this->user->update(['is_active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@tidemark.media',
            'password' => 'SecureP@ss123!',
        ]);

        // Returns same generic message — does not reveal the user is inactive
        $response->assertStatus(401)
            ->assertJson([
                'errors' => [['message' => 'Invalid credentials.']],
            ]);
    }

    // ── Account lockout ──────────────────────────────────────

    public function test_account_lockout_after_5_failed_attempts(): void
    {
        // Bypass route rate limiter — we're testing the AuthService lockout, not middleware throttle
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Cache::flush();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'test@tidemark.media',
                'password' => 'WrongPassword!',
            ]);
        }

        // 6th attempt should be blocked
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@tidemark.media',
            'password' => 'SecureP@ss123!',
        ]);

        $response->assertStatus(429)
            ->assertJson([
                'errors' => [['message' => 'Too many login attempts. Please try again later.']],
            ]);

        // Verify security_violation logged
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security_violation',
        ]);
    }

    // ── Logout ───────────────────────────────────────────────

    public function test_logout_revokes_current_token(): void
    {
        $token = $this->user->createToken('api-token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk();

        // Token should be revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'event' => 'logout',
        ]);
    }

    // ── Me ────────────────────────────────────────────────────

    public function test_me_returns_user_with_permissions_and_tenant(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['user', 'permissions', 'tenant_name'],
            ]);

        $this->assertEquals('Test Org', $response->json('data.tenant_name'));
        $this->assertIsArray($response->json('data.permissions'));
    }

    public function test_me_includes_creator_profile_for_creator(): void
    {
        $creatorTenant = Tenant::create([
            'name' => 'Creator Tenant',
            'slug' => 'creator-tenant',
            'subscription_tier' => 'creator_pro',
            'product_tier' => 'creator',
            'is_active' => true,
        ]);

        $creator = User::factory()->create([
            'tenant_id' => $creatorTenant->id,
            'role' => 'creator',
            'is_active' => true,
        ]);
        $creator->assignRole('creator');

        CreatorProfile::withoutGlobalScope('tenant')->create([
            'tenant_id' => $creatorTenant->id,
            'user_id' => $creator->id,
            'display_name' => 'Test Creator',
            'platform_handles' => ['instagram' => '@test'],
            'content_topics' => ['tech'],
            'vibe_shield_level' => 'filtered',
            'allow_body_comments' => false,
            'allow_relationship_comments' => false,
            'allow_success_shaming' => false,
            'custom_protection_rules' => [],
            'weekly_digest_enabled' => true,
            'surge_alert_threshold_multiplier' => 10.0,
        ]);

        $response = $this->actingAs($creator, 'sanctum')
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.creator_profile.display_name', 'Test Creator');
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    // ── Forgot password ──────────────────────────────────────

    public function test_forgot_password_always_returns_200(): void
    {
        Notification::fake();

        // Real email
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'test@tidemark.media',
        ]);

        $response->assertOk();

        // Nonexistent email — MUST also return 200 (no user enumeration)
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'ghost@example.com',
        ]);

        $response->assertOk();
    }

    public function test_forgot_password_user_enumeration_prevention(): void
    {
        Notification::fake();

        // Both real and fake emails get identical response
        $realResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'test@tidemark.media',
        ]);

        $fakeResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'doesnotexist@example.com',
        ]);

        // Same status code
        $this->assertEquals($realResponse->status(), $fakeResponse->status());

        // Same response structure
        $this->assertEquals(
            array_keys($realResponse->json()),
            array_keys($fakeResponse->json()),
        );
    }

    // ── Reset password ───────────────────────────────────────

    public function test_reset_password_enforces_complexity(): void
    {
        $token = Password::createToken($this->user);

        // Too short, no special chars
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422);
    }

    public function test_reset_password_revokes_all_tokens(): void
    {
        // Create some tokens first
        $this->user->createToken('device-1');
        $this->user->createToken('device-2');
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $token = Password::createToken($this->user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'N3wSecureP@ss!x',
            'password_confirmation' => 'N3wSecureP@ss!x',
        ]);

        $response->assertOk();

        // All tokens should be revoked
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Can login with new password
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'N3wSecureP@ss!x',
        ]);

        $loginResponse->assertOk();
    }

    // ── Cross-tenant session isolation ───────────────────────

    public function test_cross_tenant_session_isolation(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Org',
            'slug' => 'other-org',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $otherUser->assignRole('admin');

        // BBC admin should not see sessions for Other Org user
        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/sessions?user_id=' . $otherUser->id);

        $response->assertStatus(403);
    }

    public function test_admin_can_list_own_tenant_sessions(): void
    {
        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        // Create some tokens for the target user
        $this->user->createToken('device-1');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/sessions?user_id=' . $this->user->id);

        $response->assertOk();
    }

    public function test_non_admin_cannot_list_sessions(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/admin/sessions?user_id=' . $this->user->id);

        $response->assertStatus(403);
    }

    public function test_admin_can_revoke_own_tenant_sessions(): void
    {
        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $this->user->createToken('device-1');
        $this->user->createToken('device-2');

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/admin/users/' . $this->user->id . '/sessions');

        $response->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
        ]);
    }

    public function test_admin_cannot_revoke_cross_tenant_sessions(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Org',
            'slug' => 'other-org2',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role' => 'journalist',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/admin/users/' . $otherUser->id . '/sessions');

        $response->assertStatus(403);
    }

    // ── Token expiry ─────────────────────────────────────────

    public function test_login_token_has_expiry(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@tidemark.media',
            'password' => 'SecureP@ss123!',
        ]);

        $response->assertOk();

        $expiresAt = $response->json('data.expires_at');
        $this->assertNotNull($expiresAt);

        // Should expire ~8 hours from now
        $expiry = \Carbon\Carbon::parse($expiresAt);
        $this->assertTrue($expiry->isBetween(now()->addHours(7), now()->addHours(9)));
    }
}
