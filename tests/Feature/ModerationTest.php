<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\SocialConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModerationTest extends TestCase
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
            'role' => 'section_editor',
            'section' => 'news',
            'is_active' => true,
        ]);
        $this->sectionEditor->assignRole('section_editor');

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

    private function createComment(string $platform, string $status = 'pending', array $overrides = []): Comment
    {
        return Comment::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'article_id' => $this->article->id,
            'platform' => $platform,
            'original_text' => 'Test comment text',
            'commenter_platform_id' => 'cmt_' . uniqid(),
            'status' => $status,
            'toxicity_score' => 65,
            'confidence_score' => 80,
        ], $overrides));
    }

    private function createConnection(string $platform): SocialConnection
    {
        return SocialConnection::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'platform' => $platform,
            'platform_page_id' => "page_{$platform}_123",
            'platform_page_name' => 'Test ' . ucfirst($platform),
            'access_token' => 'test-token',
            'refresh_token' => 'test-refresh',
            'token_expires_at' => now()->addDays(30),
            'is_active' => true,
            'needs_reauth' => false,
        ]);
    }

    // ── Instagram hide returns 422 ──────────────────────────

    public function test_instagram_hide_returns_422(): void
    {
        $comment = $this->createComment('instagram');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/hide');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Instagram and TikTok do not support hiding — use the delete action instead');
    }

    // ── TikTok hide returns 422 ─────────────────────────────

    public function test_tiktok_hide_returns_422(): void
    {
        $comment = $this->createComment('tiktok');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/hide');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Instagram and TikTok do not support hiding — use the delete action instead');
    }

    // ── Facebook delete without hidden status returns 422 ───

    public function test_facebook_delete_without_hidden_returns_422(): void
    {
        $comment = $this->createComment('facebook', 'pending');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/delete');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Use confirm_delete for Facebook/YouTube/Website');
    }

    // ── YouTube delete without hidden status returns 422 ────

    public function test_youtube_delete_without_hidden_returns_422(): void
    {
        $comment = $this->createComment('youtube', 'pending');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/delete');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Use confirm_delete for Facebook/YouTube/Website');
    }

    // ── Website delete without hidden status returns 422 ────

    public function test_website_delete_without_hidden_returns_422(): void
    {
        $comment = $this->createComment('website', 'pending');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/delete');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Use confirm_delete for Facebook/YouTube/Website');
    }

    // ── confirm_delete requires section_editor or above ─────

    public function test_confirm_delete_requires_section_editor(): void
    {
        $comment = $this->createComment('facebook', 'hidden', ['hidden_at' => now()]);

        // Journalist should get 403
        $response = $this->actingAs($this->journalist)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/confirm-delete');

        $response->assertStatus(403);

        // Section editor should succeed
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->createConnection('facebook');

        $response = $this->actingAs($this->sectionEditor)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/confirm-delete');

        $response->assertStatus(200);
    }

    // ── Substack returns 422 on any moderation action ───────

    public function test_substack_returns_422_on_hide(): void
    {
        $comment = $this->createComment('substack');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/hide');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Substack does not support comment moderation via API.');
    }

    public function test_substack_returns_422_on_approve(): void
    {
        $comment = $this->createComment('substack');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/approve');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Substack does not support comment moderation via API.');
    }

    public function test_substack_returns_422_on_ban_user(): void
    {
        $comment = $this->createComment('substack');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/ban-user');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Substack does not support comment moderation via API.');
    }

    // ── Restore only works on hidden comments ───────────────

    public function test_restore_only_works_on_hidden_comments(): void
    {
        $comment = $this->createComment('facebook', 'pending');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/restore');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Only hidden comments can be restored.');
    }

    public function test_restore_works_for_hidden_facebook_comment(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->createConnection('facebook');

        $comment = $this->createComment('facebook', 'hidden', ['hidden_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/restore');

        $response->assertStatus(200);
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'status' => 'restored']);
    }

    public function test_restore_returns_422_for_instagram(): void
    {
        $comment = $this->createComment('instagram', 'hidden', ['hidden_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/restore');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Instagram and TikTok comments cannot be restored — they are permanently deleted when flagged.');
    }

    // ── Queue endpoint ──────────────────────────────────────

    public function test_queue_returns_paginated_pending_comments(): void
    {
        $this->createComment('facebook', 'pending');
        $this->createComment('youtube', 'pending');
        $this->createComment('instagram', 'approved');

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/moderation/queue');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_queue_filters_by_platform(): void
    {
        $this->createComment('facebook', 'pending');
        $this->createComment('youtube', 'pending');

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/moderation/queue?platform=facebook');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.platform', 'facebook');
    }

    // ── Hidden library ──────────────────────────────────────

    public function test_hidden_library_excludes_instagram_and_tiktok(): void
    {
        $this->createComment('facebook', 'hidden', ['hidden_at' => now()]);
        $this->createComment('youtube', 'hidden', ['hidden_at' => now()]);
        $this->createComment('instagram', 'hidden', ['hidden_at' => now()]);
        $this->createComment('tiktok', 'hidden', ['hidden_at' => now()]);
        $this->createComment('website', 'hidden', ['hidden_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/moderation/hidden-library');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');

        $platforms = collect($response->json('data'))->pluck('platform')->all();
        $this->assertNotContains('instagram', $platforms);
        $this->assertNotContains('tiktok', $platforms);
    }

    public function test_hidden_library_includes_platform_note(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/moderation/hidden-library');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'platform_note' => 'Instagram and TikTok comments are permanently deleted when flagged — they cannot be reviewed or recovered. This library contains only Facebook, YouTube and website comments hidden pending review.',
        ]);
    }

    public function test_hidden_library_requires_section_editor_or_above(): void
    {
        $response = $this->actingAs($this->journalist)
            ->getJson('/api/v1/moderation/hidden-library');

        $response->assertStatus(403);
    }

    // ── Stats endpoint ──────────────────────────────────────

    public function test_stats_returns_expected_keys(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/moderation/stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'pending_count',
                'hidden_today',
                'approved_today',
                'deleted_today',
                'restored_today',
                'confirmed_deleted_today',
                'auto_handle_rate',
                'tidemark_score',
            ],
        ]);
    }

    // ── Instagram delete works ──────────────────────────────

    public function test_instagram_delete_succeeds(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->createConnection('instagram');

        $comment = $this->createComment('instagram');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/delete');

        $response->assertStatus(200);
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'status' => 'deleted']);
        $this->assertDatabaseHas('moderation_actions', ['comment_id' => $comment->id, 'action' => 'delete']);
    }

    // ── Facebook hide works ─────────────────────────────────

    public function test_facebook_hide_succeeds(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->createConnection('facebook');

        $comment = $this->createComment('facebook');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/hide');

        $response->assertStatus(200);
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'status' => 'hidden']);
        $this->assertDatabaseHas('moderation_actions', ['comment_id' => $comment->id, 'action' => 'hide']);
    }

    // ── Approve works ───────────────────────────────────────

    public function test_approve_succeeds(): void
    {
        $comment = $this->createComment('facebook');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/approve');

        $response->assertStatus(200);
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'status' => 'approved']);
        $this->assertDatabaseHas('moderation_actions', ['comment_id' => $comment->id, 'action' => 'approve']);
    }

    // ── Confirm delete for hidden Facebook comment ──────────

    public function test_confirm_delete_succeeds_for_hidden_facebook(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->createConnection('facebook');

        $comment = $this->createComment('facebook', 'hidden', ['hidden_at' => now()]);

        $response = $this->actingAs($this->sectionEditor)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/confirm-delete');

        $response->assertStatus(200);
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'status' => 'confirmed_deleted']);
        $this->assertDatabaseHas('moderation_actions', ['comment_id' => $comment->id, 'action' => 'confirm_delete']);
    }

    // ── confirm_delete returns 422 if not hidden ────────────

    public function test_confirm_delete_returns_422_if_not_hidden(): void
    {
        $comment = $this->createComment('facebook', 'pending');

        $response = $this->actingAs($this->sectionEditor)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/confirm-delete');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Only hidden comments can be confirmed for deletion.');
    }

    // ── confirm_delete returns 422 for instagram/tiktok ─────

    public function test_confirm_delete_returns_422_for_instagram(): void
    {
        $comment = $this->createComment('instagram', 'hidden', ['hidden_at' => now()]);

        $response = $this->actingAs($this->sectionEditor)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/confirm-delete');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.message', 'Instagram and TikTok comments do not use confirm_delete — they are deleted immediately.');
    }

    // ── Ban user requires section_editor or above ───────────

    public function test_ban_user_requires_section_editor(): void
    {
        $comment = $this->createComment('facebook');

        $response = $this->actingAs($this->journalist)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/ban-user');

        $response->assertStatus(403);
    }

    public function test_ban_user_succeeds_for_section_editor(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->createConnection('facebook');

        $comment = $this->createComment('facebook');

        $response = $this->actingAs($this->sectionEditor)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/ban-user');

        $response->assertStatus(200);
        $this->assertDatabaseHas('moderation_actions', ['comment_id' => $comment->id, 'action' => 'ban_user']);
    }

    // ── Cross-tenant access returns 403 ─────────────────────

    public function test_cross_tenant_moderation_returns_403(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Publisher',
            'slug' => 'other-publisher',
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

        $comment = $this->createComment('facebook');

        $response = $this->actingAs($otherUser)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/hide');

        $response->assertStatus(403);
    }

    // ── Journalist can only moderate own articles ────────────

    public function test_journalist_can_moderate_own_articles(): void
    {
        $comment = $this->createComment('facebook');

        $response = $this->actingAs($this->journalist)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/approve');

        $response->assertStatus(200);
    }

    public function test_journalist_cannot_moderate_other_articles(): void
    {
        $otherJournalist = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'journalist',
            'section' => 'sport',
            'is_active' => true,
        ]);
        $otherJournalist->assignRole('journalist');

        $otherArticle = Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'journalist_id' => $otherJournalist->id,
            'title' => 'Other Article',
            'url' => 'https://example.com/other',
            'platform' => 'website',
            'published_at' => now(),
        ]);

        $comment = Comment::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'article_id' => $otherArticle->id,
            'platform' => 'facebook',
            'original_text' => 'Test comment',
            'commenter_platform_id' => 'cmt_other_' . uniqid(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->journalist)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/approve');

        $response->assertStatus(403);
    }

    // ── Website hide is DB-only ─────────────────────────────

    public function test_website_hide_is_db_only(): void
    {
        Http::fake();

        $comment = $this->createComment('website');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/moderation/comments/' . $comment->id . '/hide');

        $response->assertStatus(200);
        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'status' => 'hidden',
            'hidden_by_ai' => false,
        ]);

        Http::assertNothingSent();
    }
}
