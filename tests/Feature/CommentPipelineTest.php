<?php

namespace Tests\Feature;

use App\Jobs\CheckEngagementEligibilityJob;
use App\Jobs\ClassifyCommentJob;
use App\Jobs\DraftEngagementResponseJob;
use App\Jobs\RouteCommentJob;
use App\Models\Article;
use App\Models\Comment;
use App\Models\SocialConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SocialMedia\FacebookService;
use App\Services\SocialMedia\InstagramService;
use App\Services\SocialMedia\SubstackService;
use App\Services\SocialMedia\TikTokService;
use App\Services\SocialMedia\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommentPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Article $article;

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

        $this->article = Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'journalist_id' => $this->admin->id,
            'title' => 'Test Article',
            'url' => 'https://example.com/test',
            'platform' => 'website',
            'published_at' => now(),
        ]);
    }

    private function createConnection(string $platform, array $overrides = []): SocialConnection
    {
        return SocialConnection::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'platform' => $platform,
            'platform_page_id' => "page_{$platform}_123",
            'platform_page_name' => 'Test ' . ucfirst($platform),
            'access_token' => 'test-token',
            'refresh_token' => 'test-refresh',
            'token_expires_at' => now()->addDays(30),
            'is_active' => true,
            'needs_reauth' => false,
        ], $overrides));
    }

    private function createComment(string $platform, ?string $decision, array $overrides = []): Comment
    {
        return Comment::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'article_id' => $this->article->id,
            'platform' => $platform,
            'original_text' => 'Test comment',
            'commenter_platform_id' => 'comment_' . uniqid(),
            'status' => 'pending',
            'routing_decision' => $decision,
            'toxicity_score' => 85,
            'confidence_score' => 90,
        ], $overrides));
    }

    // ── Instagram: never calls hide API — only delete ────────

    public function test_instagram_delete_calls_delete_api(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $connection = $this->createConnection('instagram');
        $comment = $this->createComment('instagram', 'delete');

        $job = new RouteCommentJob($comment);
        $job->handle();

        $comment->refresh();
        $this->assertEquals('deleted', $comment->status);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_instagram_never_calls_hide_api(): void
    {
        Http::fake();

        $connection = $this->createConnection('instagram');
        $comment = $this->createComment('instagram', 'hide');

        $job = new RouteCommentJob($comment);
        $job->handle();

        $comment->refresh();
        // 'hide' on Instagram should become pending (manual review), not call hide API
        $this->assertEquals('pending', $comment->status);
        $this->assertFalse($comment->hidden_by_ai);

        // No HTTP calls should have been made for hiding
        Http::assertNothingSent();
    }

    public function test_instagram_approve_sets_approved(): void
    {
        $this->createConnection('instagram');
        $comment = $this->createComment('instagram', 'approve');

        $job = new RouteCommentJob($comment);
        $job->handle();

        $comment->refresh();
        $this->assertEquals('approved', $comment->status);
    }

    // ── TikTok: never calls hide API — only delete ───────────

    public function test_tiktok_delete_calls_delete_api(): void
    {
        Http::fake(['open.tiktokapis.com/*' => Http::response(['data' => ['status' => 'ok']])]);

        $connection = $this->createConnection('tiktok');
        $comment = $this->createComment('tiktok', 'delete');

        $job = new RouteCommentJob($comment);
        $job->handle();

        $comment->refresh();
        $this->assertEquals('deleted', $comment->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'tiktokapis.com')
            && str_contains($request->url(), 'delete'));
    }

    public function test_tiktok_never_calls_hide_api(): void
    {
        Http::fake();

        $connection = $this->createConnection('tiktok');
        $comment = $this->createComment('tiktok', 'hide');

        $job = new RouteCommentJob($comment);
        $job->handle();

        $comment->refresh();
        // 'hide' on TikTok should become pending (manual review)
        $this->assertEquals('pending', $comment->status);
        $this->assertFalse($comment->hidden_by_ai);

        Http::assertNothingSent();
    }

    // ── Facebook/YouTube: call hide not delete on auto-action ─

    public function test_facebook_hide_calls_hide_api(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $connection = $this->createConnection('facebook');
        $comment = $this->createComment('facebook', 'hide');

        $job = new RouteCommentJob($comment);
        $job->handle();

        $comment->refresh();
        $this->assertEquals('hidden', $comment->status);
        $this->assertTrue($comment->hidden_by_ai);
        $this->assertNotNull($comment->hidden_at);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'graph.facebook.com')
            && $request->data()['is_hidden'] === true);
    }

    public function test_youtube_hide_calls_moderation_api(): void
    {
        Http::fake(['googleapis.com/*' => Http::response(['success' => true])]);

        $connection = $this->createConnection('youtube');
        $article = Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'journalist_id' => $this->admin->id,
            'title' => 'YT Video',
            'platform' => 'youtube',
            'platform_post_id' => 'yt_vid_123',
            'published_at' => now(),
        ]);
        $comment = $this->createComment('youtube', 'hide', ['article_id' => $article->id]);

        $job = new RouteCommentJob($comment);
        $job->handle();

        $comment->refresh();
        $this->assertEquals('hidden', $comment->status);
        $this->assertTrue($comment->hidden_by_ai);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'googleapis.com')
            && str_contains($request->url(), 'setModerationStatus'));
    }

    // ── Website: set visible=false not hard delete ────────────

    public function test_website_hide_sets_hidden_status(): void
    {
        $comment = $this->createComment('website', 'hide');

        $job = new RouteCommentJob($comment);
        $job->handle();

        $comment->refresh();
        $this->assertEquals('hidden', $comment->status);
        $this->assertTrue($comment->hidden_by_ai);
        $this->assertNotNull($comment->hidden_at);

        // No external API call for website — it's just a DB update
        // Comment is NOT hard-deleted
        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    }

    public function test_website_approve_sets_approved(): void
    {
        $comment = $this->createComment('website', 'approve');

        $job = new RouteCommentJob($comment);
        $job->handle();

        $comment->refresh();
        $this->assertEquals('approved', $comment->status);
    }

    // ── Substack: never makes any external API call ──────────

    public function test_substack_service_never_makes_api_calls(): void
    {
        Http::fake();

        $connection = $this->createConnection('substack');
        $service = new SubstackService();

        $service->pollNewComments($connection, $this->tenant);

        Http::assertNothingSent();
    }

    public function test_substack_hide_returns_false(): void
    {
        $connection = $this->createConnection('substack');
        $service = new SubstackService();

        $this->assertFalse($service->hideComment('any_id', $connection));
    }

    public function test_substack_delete_returns_false(): void
    {
        $connection = $this->createConnection('substack');
        $service = new SubstackService();

        $this->assertFalse($service->deleteComment('any_id', $connection));
    }

    public function test_route_comment_job_skips_substack(): void
    {
        Http::fake();

        $comment = $this->createComment('substack', 'hide');

        $job = new RouteCommentJob($comment);
        $job->handle();

        // Status should remain unchanged (pending)
        $comment->refresh();
        $this->assertEquals('pending', $comment->status);

        Http::assertNothingSent();
    }

    // ── HMAC webhook validation ──────────────────────────────

    public function test_webhook_rejects_missing_signature(): void
    {
        $response = $this->postJson("/api/v1/webhooks/{$this->tenant->id}/comments", [
            'article_url' => 'https://example.com/test',
            'comment_text' => 'Hello',
        ]);

        $response->assertStatus(401);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $response = $this->postJson(
            "/api/v1/webhooks/{$this->tenant->id}/comments",
            ['article_url' => 'https://example.com/test', 'comment_text' => 'Hello'],
            ['X-Tidemark-Signature' => 'bogus-signature'],
        );

        $response->assertStatus(401);
    }

    public function test_webhook_accepts_valid_signature(): void
    {
        Queue::fake();

        config(['services.tidemark.webhook_secret' => 'test-secret']);

        $payload = json_encode([
            'article_url' => 'https://example.com/test',
            'comment_text' => 'Great article!',
        ]);

        $signature = hash_hmac('sha256', $payload, 'test-secret');

        $response = $this->call(
            'POST',
            "/api/v1/webhooks/{$this->tenant->id}/comments",
            [],
            [],
            [],
            [
                'HTTP_X_Tidemark_Signature' => $signature,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload,
        );

        $response->assertOk();
        $this->assertDatabaseHas('comments', [
            'tenant_id' => $this->tenant->id,
            'platform' => 'website',
            'original_text' => 'Great article!',
        ]);

        Queue::assertPushed(ClassifyCommentJob::class);
    }

    // ── Cross-tenant isolation in ingestion ───────────────────

    public function test_webhook_cannot_process_another_tenants_articles(): void
    {
        Queue::fake();
        config(['services.tidemark.webhook_secret' => 'test-secret']);

        $otherTenant = Tenant::create([
            'name' => 'Other Publisher',
            'slug' => 'other-publisher',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        // Article belongs to $this->tenant, but webhook arrives for $otherTenant
        $payload = json_encode([
            'article_url' => 'https://example.com/test',
            'comment_text' => 'Cross-tenant attack',
        ]);

        $signature = hash_hmac('sha256', $payload, 'test-secret');

        $response = $this->call(
            'POST',
            "/api/v1/webhooks/{$otherTenant->id}/comments",
            [],
            [],
            [],
            [
                'HTTP_X_Tidemark_Signature' => $signature,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload,
        );

        // Returns 200 (webhook received) but no comment created because article lookup
        // scoped to otherTenant won't find $this->tenant's article
        $response->assertOk();

        $this->assertDatabaseMissing('comments', [
            'tenant_id' => $otherTenant->id,
            'original_text' => 'Cross-tenant attack',
        ]);

        Queue::assertNotPushed(ClassifyCommentJob::class);
    }

    // ── ClassifyCommentJob dispatches RouteCommentJob ─────────

    public function test_classify_comment_job_updates_and_dispatches_route(): void
    {
        Queue::fake();
        Http::fake([
            '*/classify' => Http::response([
                'normalised_text' => 'normalised comment',
                'toxicity_score' => 15,
                'confidence_score' => 95,
                'routing_decision' => 'approve',
                'flagged_reason' => null,
                'is_personal_attack' => false,
                'target_entity' => null,
            ]),
        ]);

        $comment = $this->createComment('facebook', null, [
            'toxicity_score' => null,
            'confidence_score' => null,
        ]);

        $job = new ClassifyCommentJob($comment);
        $job->handle();

        $comment->refresh();
        $this->assertEquals('normalised comment', $comment->normalised_text);
        $this->assertEquals(15, $comment->toxicity_score);
        $this->assertEquals('approve', $comment->routing_decision);

        Queue::assertPushed(RouteCommentJob::class);
    }

    // ── Engagement eligibility after routing ──────────────────

    public function test_approved_low_toxicity_dispatches_engagement_check(): void
    {
        Queue::fake();

        $comment = $this->createComment('website', 'approve', [
            'toxicity_score' => 10,
        ]);

        $job = new RouteCommentJob($comment);
        $job->handle();

        Queue::assertPushed(CheckEngagementEligibilityJob::class);
    }

    public function test_approved_high_toxicity_does_not_dispatch_engagement(): void
    {
        Queue::fake();

        $comment = $this->createComment('website', 'approve', [
            'toxicity_score' => 80,
        ]);

        $job = new RouteCommentJob($comment);
        $job->handle();

        Queue::assertNotPushed(CheckEngagementEligibilityJob::class);
    }

    // ── Poll command skips substack and reauth connections ────

    public function test_poll_command_skips_substack_connections(): void
    {
        Http::fake();

        $this->createConnection('substack');

        $this->artisan('social:poll-comments')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_poll_command_skips_needs_reauth_connections(): void
    {
        Http::fake();

        $this->createConnection('facebook', ['needs_reauth' => true]);

        $this->artisan('social:poll-comments')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
