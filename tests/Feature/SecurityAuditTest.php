<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\EngagementResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Jobs\DraftEngagementResponseJob;
use App\Jobs\PostEngagementResponseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $bbc;

    private Tenant $guardian;

    private User $bbcUser;

    private User $guardianUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->bbc = Tenant::create([
            'name' => 'BBC',
            'slug' => 'bbc',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $this->guardian = Tenant::create([
            'name' => 'The Guardian',
            'slug' => 'the-guardian',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $this->bbcUser = User::factory()->create([
            'tenant_id' => $this->bbc->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->bbcUser->assignRole('admin');

        $this->guardianUser = User::factory()->create([
            'tenant_id' => $this->guardian->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->guardianUser->assignRole('admin');
    }

    // ── Tenant Isolation ────────────────────────────────────

    private function createGuardianComment(array $overrides = []): Comment
    {
        $article = Article::factory()->create([
            'tenant_id' => $this->guardian->id,
            'journalist_id' => $this->guardianUser->id,
        ]);

        return Comment::factory()->create(array_merge([
            'tenant_id' => $this->guardian->id,
            'article_id' => $article->id,
        ], $overrides));
    }

    private function createBbcArticleAndComment(array $commentOverrides = []): array
    {
        $article = Article::factory()->create([
            'tenant_id' => $this->bbc->id,
            'journalist_id' => $this->bbcUser->id,
        ]);

        $comment = Comment::factory()->create(array_merge([
            'tenant_id' => $this->bbc->id,
            'article_id' => $article->id,
        ], $commentOverrides));

        return [$article, $comment];
    }

    public function test_bbc_user_cannot_view_guardian_comment(): void
    {
        $comment = $this->createGuardianComment();

        $response = $this->actingAs($this->bbcUser)
            ->getJson("/api/v1/comments/{$comment->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security_violation',
        ]);
    }

    public function test_bbc_user_cannot_moderate_guardian_comment(): void
    {
        $comment = $this->createGuardianComment([
            'platform' => 'website',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->bbcUser)
            ->postJson("/api/v1/moderation/comments/{$comment->id}/hide");

        $response->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security_violation',
        ]);
    }

    public function test_bbc_user_cannot_delete_guardian_comment(): void
    {
        $comment = $this->createGuardianComment([
            'platform' => 'instagram',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->bbcUser)
            ->postJson("/api/v1/moderation/comments/{$comment->id}/delete");

        $response->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security_violation',
        ]);
    }

    public function test_bbc_user_cannot_approve_guardian_comment(): void
    {
        $comment = $this->createGuardianComment([
            'platform' => 'website',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->bbcUser)
            ->postJson("/api/v1/moderation/comments/{$comment->id}/approve");

        $response->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security_violation',
        ]);
    }

    public function test_bbc_user_cannot_ban_guardian_commenter(): void
    {
        $comment = $this->createGuardianComment([
            'platform' => 'website',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->bbcUser)
            ->postJson("/api/v1/moderation/comments/{$comment->id}/ban-user", [
                'reason' => 'Testing cross-tenant ban',
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security_violation',
        ]);
    }

    public function test_bbc_user_cannot_restore_guardian_comment(): void
    {
        $comment = $this->createGuardianComment([
            'platform' => 'website',
            'status' => 'hidden',
        ]);

        $response = $this->actingAs($this->bbcUser)
            ->postJson("/api/v1/moderation/comments/{$comment->id}/restore");

        $response->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security_violation',
        ]);
    }

    public function test_cross_tenant_returns_403_not_404(): void
    {
        $comment = $this->createGuardianComment();

        $response = $this->actingAs($this->bbcUser)
            ->getJson("/api/v1/comments/{$comment->id}");

        // Must be 403 (Forbidden), never 404 (which would leak existence)
        $response->assertStatus(403);
    }

    // ── Engagement Guardrails ───────────────────────────────

    public function test_draft_job_skips_hidden_comment(): void
    {
        [$article, $comment] = $this->createBbcArticleAndComment([
            'status' => 'hidden',
            'toxicity_score' => 5,
        ]);

        $job = new DraftEngagementResponseJob($comment);
        $job->handle();

        $this->assertDatabaseMissing('engagement_responses', [
            'comment_id' => $comment->id,
        ]);
    }

    public function test_draft_job_skips_deleted_comment(): void
    {
        [$article, $comment] = $this->createBbcArticleAndComment([
            'status' => 'deleted',
            'toxicity_score' => 5,
        ]);

        $job = new DraftEngagementResponseJob($comment);
        $job->handle();

        $this->assertDatabaseMissing('engagement_responses', [
            'comment_id' => $comment->id,
        ]);
    }

    public function test_draft_job_skips_toxic_comment(): void
    {
        [$article, $comment] = $this->createBbcArticleAndComment([
            'status' => 'approved',
            'toxicity_score' => 50,
        ]);

        $job = new DraftEngagementResponseJob($comment);
        $job->handle();

        $this->assertDatabaseMissing('engagement_responses', [
            'comment_id' => $comment->id,
        ]);
    }

    public function test_post_job_skips_hidden_comment(): void
    {
        [$article, $comment] = $this->createBbcArticleAndComment([
            'status' => 'hidden',
        ]);

        $engagementResponse = EngagementResponse::create([
            'tenant_id' => $this->bbc->id,
            'comment_id' => $comment->id,
            'article_id' => $article->id,
            'platform' => $comment->platform,
            'draft_text' => 'Thanks for your feedback!',
            'response_type' => 'reply',
            'status' => 'approved',
            'drafted_by_ai' => true,
        ]);

        $job = new PostEngagementResponseJob($engagementResponse);
        $job->handle();

        $engagementResponse->refresh();
        $this->assertEquals('rejected', $engagementResponse->status);
    }

    public function test_post_job_blocks_response_containing_url(): void
    {
        [$article, $comment] = $this->createBbcArticleAndComment([
            'status' => 'approved',
        ]);

        $engagementResponse = EngagementResponse::create([
            'tenant_id' => $this->bbc->id,
            'comment_id' => $comment->id,
            'article_id' => $article->id,
            'platform' => $comment->platform,
            'draft_text' => 'Check out https://malicious.com for more info',
            'response_type' => 'reply',
            'status' => 'approved',
            'drafted_by_ai' => true,
        ]);

        $job = new PostEngagementResponseJob($engagementResponse);
        $job->handle();

        $engagementResponse->refresh();
        $this->assertEquals('draft', $engagementResponse->status);
    }

    public function test_post_job_requeues_when_paused(): void
    {
        Bus::fake(PostEngagementResponseJob::class);

        [$article, $comment] = $this->createBbcArticleAndComment([
            'status' => 'approved',
            'platform' => 'website',
        ]);

        $engagementResponse = EngagementResponse::create([
            'tenant_id' => $this->bbc->id,
            'comment_id' => $comment->id,
            'article_id' => $article->id,
            'platform' => 'website',
            'draft_text' => 'Thanks for your feedback!',
            'response_type' => 'reply',
            'status' => 'approved',
            'drafted_by_ai' => true,
        ]);

        Cache::put("engagement:paused:{$this->bbc->id}", true, now()->addHour());

        // Run the job directly (Bus::fake prevents the re-dispatch from executing)
        $job = new PostEngagementResponseJob($engagementResponse);
        $job->handle();

        $engagementResponse->refresh();
        // Status should remain unchanged (job requeued, didn't post or reject)
        $this->assertEquals('approved', $engagementResponse->status);

        // Verify the job was re-dispatched
        Bus::assertDispatched(PostEngagementResponseJob::class);
    }

    // ── Body Size Middleware ────────────────────────────────

    public function test_request_body_over_100kb_is_rejected(): void
    {
        // Simulate a large Content-Length header without actually sending a massive body
        $response = $this->actingAs($this->bbcUser)
            ->call('POST', '/api/v1/comments', [], [], [], [
                'HTTP_CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_CONTENT_LENGTH' => '200000',
            ], '{}');

        $response->assertStatus(413);
    }
}
