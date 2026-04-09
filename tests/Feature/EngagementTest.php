<?php

namespace Tests\Feature;

use App\Jobs\PostEngagementResponseJob;
use App\Jobs\TrainToneProfileJob;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\EngagementResponse;
use App\Models\Tenant;
use App\Models\ToneOfVoiceProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $sectionEditor;

    private User $journalist;

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
            'section' => 'news',
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');

        $this->sectionEditor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'senior_editor',
            'section' => 'news',
            'is_active' => true,
        ]);
        $this->sectionEditor->assignRole('senior_editor');

        $this->journalist = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'journalist',
            'section' => 'news',
            'is_active' => true,
        ]);
        $this->journalist->assignRole('journalist');

        $this->article = Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'journalist_id' => $this->journalist->id,
            'title' => 'Test Article',
            'url' => 'https://example.com/test',
            'platform' => 'website',
            'published_at' => now(),
            'topic_category' => 'politics',
        ]);
    }

    private function createComment(array $overrides = []): Comment
    {
        return Comment::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'article_id' => $this->article->id,
            'platform' => 'website',
            'original_text' => 'Great article, very informative!',
            'commenter_platform_id' => 'usr_' . uniqid(),
            'status' => 'approved',
            'toxicity_score' => 5,
            'confidence_score' => 90,
        ], $overrides));
    }

    private function createDraft(array $overrides = []): EngagementResponse
    {
        $comment = $this->createComment();

        return EngagementResponse::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'comment_id' => $comment->id,
            'article_id' => $this->article->id,
            'platform' => 'website',
            'draft_text' => 'Thank you for reading!',
            'response_type' => 'thank_you',
            'status' => 'draft',
            'drafted_by_ai' => true,
        ], $overrides));
    }

    // ── Queue ────────────────────────────────────────────────

    public function test_queue_returns_draft_responses(): void
    {
        $draft = $this->createDraft();
        // non-draft should not appear
        $this->createDraft(['status' => 'posted']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/engagement/queue');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $draft->id);
    }

    public function test_queue_filters_by_platform(): void
    {
        $this->createDraft(['platform' => 'website']);
        $this->createDraft(['platform' => 'facebook']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/engagement/queue?platform=website');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_queue_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/engagement/queue');

        $response->assertStatus(401);
    }

    // ── Approve ──────────────────────────────────────────────

    public function test_approve_draft(): void
    {
        Bus::fake([PostEngagementResponseJob::class]);

        $draft = $this->createDraft();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Draft approved and queued for posting.');

        $draft->refresh();
        $this->assertEquals('approved', $draft->status);
        $this->assertEquals($this->admin->id, $draft->approved_by);
        $this->assertNotNull($draft->approved_at);

        Bus::assertDispatched(PostEngagementResponseJob::class);
    }

    public function test_approve_non_draft_returns_422(): void
    {
        $draft = $this->createDraft(['status' => 'posted']);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/approve");

        $response->assertStatus(422);
    }

    public function test_approve_creates_audit_log(): void
    {
        Bus::fake([PostEngagementResponseJob::class]);

        $draft = $this->createDraft();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/approve");

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'engagement_approved',
            'user_id' => $this->admin->id,
        ]);
    }

    // ── Edit and approve ─────────────────────────────────────

    public function test_edit_and_approve_draft(): void
    {
        Bus::fake([PostEngagementResponseJob::class]);

        $draft = $this->createDraft();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/edit-and-approve", [
                'text' => 'Thanks for your thoughtful comment!',
            ]);

        $response->assertOk();

        $draft->refresh();
        $this->assertEquals('approved', $draft->status);
        $this->assertEquals('Thanks for your thoughtful comment!', $draft->final_text);

        Bus::assertDispatched(PostEngagementResponseJob::class);
    }

    public function test_edit_and_approve_rejects_urls(): void
    {
        $draft = $this->createDraft();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/edit-and-approve", [
                'text' => 'Check out https://spam.com for more info!',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Response text must not contain URLs.');
    }

    public function test_edit_and_approve_rejects_html(): void
    {
        $draft = $this->createDraft();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/edit-and-approve", [
                'text' => '<b>Bold</b> marketing text',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Response text must not contain HTML.');
    }

    public function test_edit_and_approve_rejects_over_500_chars(): void
    {
        $draft = $this->createDraft();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/edit-and-approve", [
                'text' => str_repeat('a', 501),
            ]);

        $response->assertStatus(422);
    }

    public function test_edit_and_approve_creates_audit_with_original(): void
    {
        Bus::fake([PostEngagementResponseJob::class]);

        $draft = $this->createDraft(['draft_text' => 'Original AI draft']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/edit-and-approve", [
                'text' => 'Human edited version',
            ]);

        $log = AuditLog::withoutGlobalScope('tenant')
            ->where('event', 'engagement_edited_and_approved')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Original AI draft', $log->properties['original_draft']);
        $this->assertEquals('Human edited version', $log->properties['edited_text']);
    }

    // ── Reject ───────────────────────────────────────────────

    public function test_reject_draft(): void
    {
        $draft = $this->createDraft();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/reject", [
                'reason' => 'Tone is not appropriate',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Draft rejected.');

        $draft->refresh();
        $this->assertEquals('rejected', $draft->status);
    }

    public function test_reject_without_reason(): void
    {
        $draft = $this->createDraft();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/reject");

        $response->assertOk();

        $draft->refresh();
        $this->assertEquals('rejected', $draft->status);
    }

    public function test_reject_creates_audit_log(): void
    {
        $draft = $this->createDraft();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/engagement/drafts/{$draft->id}/reject", [
                'reason' => 'Inappropriate tone',
            ]);

        $log = AuditLog::withoutGlobalScope('tenant')
            ->where('event', 'engagement_rejected')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Inappropriate tone', $log->properties['reason']);
    }

    // ── Tone profiles ────────────────────────────────────────

    public function test_create_tone_profile(): void
    {
        Bus::fake([TrainToneProfileJob::class]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/engagement/tone-profiles', [
                'name' => 'Friendly Newsroom',
                'formality_level' => 'informal',
                'personality_traits' => ['warm', 'helpful'],
                'example_responses' => [
                    'Thanks for reading! Glad you enjoyed it.',
                    'Great question — we\'ll look into that.',
                    'Appreciate you taking the time to comment!',
                ],
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Friendly Newsroom');

        Bus::assertDispatched(TrainToneProfileJob::class);
    }

    public function test_tone_profile_requires_min_3_examples(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/engagement/tone-profiles', [
                'name' => 'Test Profile',
                'example_responses' => ['one', 'two'],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.field', 'example_responses');
    }

    public function test_tone_profile_requires_admin_or_senior_editor(): void
    {
        $response = $this->actingAs($this->journalist)
            ->postJson('/api/v1/engagement/tone-profiles', [
                'name' => 'Test',
                'example_responses' => ['a', 'b', 'c'],
            ]);

        $response->assertStatus(403);
    }

    // ── Performance ──────────────────────────────────────────

    public function test_performance_returns_stats(): void
    {
        // Create some posted responses
        $this->createDraft(['status' => 'posted', 'posted_at' => now()]);
        $this->createDraft(['status' => 'posted', 'posted_at' => now()]);
        $this->createDraft(['status' => 'draft']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/engagement/performance');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'response_rate',
                'avg_response_time_min',
                'by_response_type',
                'comparison' => ['current_period_rate', 'previous_period_rate', 'change'],
            ],
        ]);

        $data = $response->json('data');
        $this->assertEqualsWithDelta(66.7, $data['response_rate'], 0.1);
    }

    // ── Pause / Resume ───────────────────────────────────────

    public function test_any_user_can_pause_engagement(): void
    {
        $response = $this->actingAs($this->journalist)
            ->postJson('/api/v1/engagement/pause');

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Engagement auto-posting paused.');

        $this->assertTrue(Cache::get("engagement:paused:{$this->tenant->id}"));
    }

    public function test_pause_creates_audit_log(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/engagement/pause');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'engagement_paused',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_only_admin_can_resume_engagement(): void
    {
        Cache::put("engagement:paused:{$this->tenant->id}", true);

        // Journalist cannot resume
        $response = $this->actingAs($this->journalist)
            ->postJson('/api/v1/engagement/resume');

        $response->assertStatus(403);

        // Admin can resume
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/engagement/resume');

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Engagement auto-posting resumed.');

        $this->assertNull(Cache::get("engagement:paused:{$this->tenant->id}"));
    }

    public function test_resume_creates_audit_log(): void
    {
        Cache::put("engagement:paused:{$this->tenant->id}", true);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/engagement/resume');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'engagement_resumed',
            'user_id' => $this->admin->id,
        ]);
    }

    // ── Guardrails ───────────────────────────────────────────

    public function test_text_validation_rejects_urls(): void
    {
        $service = app(\App\Services\EngagementService::class);
        $this->assertNotNull($service->validateResponseText('Visit https://example.com'));
    }

    public function test_text_validation_rejects_html(): void
    {
        $service = app(\App\Services\EngagementService::class);
        $this->assertNotNull($service->validateResponseText('<script>alert(1)</script>'));
    }

    public function test_text_validation_rejects_over_500(): void
    {
        $service = app(\App\Services\EngagementService::class);
        $this->assertNotNull($service->validateResponseText(str_repeat('x', 501)));
    }

    public function test_text_validation_passes_clean_text(): void
    {
        $service = app(\App\Services\EngagementService::class);
        $this->assertNull($service->validateResponseText('Thanks for reading our article!'));
    }

    public function test_draft_eligibility_rejects_hidden_comment(): void
    {
        $service = app(\App\Services\EngagementService::class);
        $comment = $this->createComment(['status' => 'hidden']);
        $this->assertNotNull($service->validateDraftEligibility($comment));
    }

    public function test_draft_eligibility_rejects_toxic_comment(): void
    {
        $service = app(\App\Services\EngagementService::class);
        $comment = $this->createComment(['toxicity_score' => 50]);
        $this->assertNotNull($service->validateDraftEligibility($comment));
    }

    public function test_draft_eligibility_passes_clean_comment(): void
    {
        $service = app(\App\Services\EngagementService::class);
        $comment = $this->createComment(['status' => 'approved', 'toxicity_score' => 10]);
        $this->assertNull($service->validateDraftEligibility($comment));
    }

    // ── Is paused ────────────────────────────────────────────

    public function test_is_paused_returns_correct_state(): void
    {
        $service = app(\App\Services\EngagementService::class);

        $this->assertFalse($service->isPaused($this->tenant->id));

        Cache::put("engagement:paused:{$this->tenant->id}", true);

        $this->assertTrue($service->isPaused($this->tenant->id));
    }
}
