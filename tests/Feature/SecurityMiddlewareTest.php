<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'subscription_tier' => 'professional',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'subscription_tier' => 'professional',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $this->userA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'admin',
        ]);
        $this->userA->assignRole('admin');

        $this->userB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role' => 'admin',
        ]);
        $this->userB->assignRole('admin');
    }

    private function createCommentForTenant(Tenant $tenant, User $journalist): Comment
    {
        $article = Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'journalist_id' => $journalist->id,
            'title' => 'Test Article',
            'url' => 'https://example.com/article',
            'platform' => 'website',
            'platform_post_id' => 'post_' . uniqid(),
            'published_at' => now(),
            'comments_enabled' => true,
        ]);

        return Comment::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'platform' => 'website',
            'original_text' => 'Test comment',
            'normalised_text' => 'test comment',
            'commenter_platform_id' => 'user_' . uniqid(),
            'commenter_display_name' => 'Tester',
            'toxicity_score' => 10,
            'confidence_score' => 90,
            'status' => 'approved',
            'routing_decision' => 'approve',
        ]);
    }

    // ── Security Headers ─────────────────────────────────

    public function test_security_headers_present_on_all_responses(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->assertHeader('Content-Security-Policy');
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_security_headers_on_authenticated_response(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/me');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy');
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_csp_header_has_correct_directives(): void
    {
        $response = $this->getJson('/api/v1/me');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString('fonts.googleapis.com', $csp);
        $this->assertStringContainsString('fonts.gstatic.com', $csp);
    }

    // ── Tenant Isolation ─────────────────────────────────

    public function test_cross_tenant_access_returns_403_not_404(): void
    {
        $comment = $this->createCommentForTenant($this->tenantB, $this->userB);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/v1/comments/{$comment->id}");

        $response->assertStatus(403);
    }

    public function test_cross_tenant_403_creates_security_violation_audit_log(): void
    {
        $comment = $this->createCommentForTenant($this->tenantB, $this->userB);

        $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/v1/comments/{$comment->id}");

        $log = AuditLog::withoutGlobalScope('tenant')
            ->where('event', 'security_violation')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->userA->id, $log->user_id);
        $this->assertEquals($this->userA->tenant_id, $log->tenant_id);
        $this->assertStringContainsString('Cross-tenant', $log->description);
    }

    public function test_same_tenant_access_succeeds(): void
    {
        $comment = $this->createCommentForTenant($this->tenantA, $this->userA);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/v1/comments/{$comment->id}");

        $response->assertSuccessful();
    }

    // ── Audit Logger ─────────────────────────────────────

    public function test_post_requests_are_audit_logged(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/comments', [
                'platform' => 'website',
                'original_text' => 'audit test',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->userA->id,
            'event' => 'api_request',
        ]);
    }

    public function test_get_requests_are_not_audit_logged(): void
    {
        AuditLog::withoutGlobalScope('tenant')->delete();

        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/comments');

        $this->assertEquals(0, AuditLog::withoutGlobalScope('tenant')
            ->where('event', 'api_request')
            ->count());
    }

    // ── Rate Limiting ────────────────────────────────────

    public function test_rate_limiting_returns_429_with_retry_after(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong',
            ]);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }
}
