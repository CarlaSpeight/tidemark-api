<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleStat;
use App\Models\Comment;
use App\Models\CommunityHighlight;
use App\Models\EngagementResponse;
use App\Models\SurgeEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $seniorEditor;

    private User $sectionEditor;

    private User $journalist;

    private User $creator;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Test Publisher',
            'slug' => 'test-publisher',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'both',
            'is_active' => true,
        ]);

        $this->admin = $this->makeUser('admin', 'news');
        $this->seniorEditor = $this->makeUser('senior_editor', 'news');
        $this->sectionEditor = $this->makeUser('section_editor', 'news');
        $this->journalist = $this->makeUser('journalist', 'news');
        $this->creator = $this->makeUser('creator', 'lifestyle');

        $this->article = Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'journalist_id' => $this->journalist->id,
            'title' => 'Test Article',
            'url' => 'https://example.com/test',
            'platform' => 'website',
            'published_at' => now(),
            'topic_category' => 'politics',
            'sensitivity_level' => 'medium',
        ]);

        ArticleStat::withoutGlobalScope('tenant')->create([
            'article_id' => $this->article->id,
            'tenant_id' => $this->tenant->id,
            'total_comments' => 10,
            'auto_approved' => 5,
            'auto_actioned' => 3,
            'queued' => 2,
            'engagement_responses_sent' => 1,
            'avg_toxicity_score' => 35.5,
            'sentiment_positive' => 0.65,
            'calculated_at' => now(),
        ]);
    }

    private function makeUser(string $role, string $section): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => $role,
            'section' => $section,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function createComment(string $status = 'pending', array $overrides = []): Comment
    {
        return Comment::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'article_id' => $this->article->id,
            'platform' => 'website',
            'original_text' => 'Test comment',
            'commenter_platform_id' => 'cmt_' . uniqid(),
            'status' => $status,
            'toxicity_score' => 40,
            'confidence_score' => 80,
        ], $overrides));
    }

    // ── Journalist Dashboard ────────────────────────────────

    public function test_journalist_dashboard_returns_expected_structure(): void
    {
        $this->createComment('pending');
        $this->createComment('approved');

        $response = $this->actingAs($this->journalist)
            ->getJson('/api/v1/reports/journalist/dashboard');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'stats' => ['comments_today', 'auto_actioned_today', 'queue_count', 'tidemark_score'],
                'trend',
                'articles_today',
                'engagement_summary' => ['drafts_today', 'awaiting_approval', 'auto_posted_today'],
            ],
        ]);
    }

    public function test_journalist_dashboard_scoped_to_own_articles(): void
    {
        $otherJournalist = $this->makeUser('journalist', 'sport');
        $otherArticle = Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'journalist_id' => $otherJournalist->id,
            'title' => 'Other Article',
            'url' => 'https://example.com/other',
            'platform' => 'website',
            'published_at' => now(),
        ]);

        $this->createComment('pending'); // owned by $this->journalist
        Comment::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'article_id' => $otherArticle->id,
            'platform' => 'website',
            'original_text' => 'Other comment',
            'commenter_platform_id' => 'cmt_other_' . uniqid(),
            'status' => 'pending',
            'toxicity_score' => 90,
        ]);

        $response = $this->actingAs($this->journalist)
            ->getJson('/api/v1/reports/journalist/dashboard');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.stats.queue_count'));
    }

    // ── Editor Dashboard ────────────────────────────────────

    public function test_editor_dashboard_returns_expected_structure(): void
    {
        $this->createComment('approved');

        $response = $this->actingAs($this->sectionEditor)
            ->getJson('/api/v1/reports/editor/dashboard');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'section_stats' => ['avg_toxicity', 'total_comments', 'auto_handle_rate', 'engagement_rate'],
                'best_posts',
                'worst_posts',
                'team',
                'topic_heatmap',
                'engagement_performance' => ['response_rate', 'avg_response_time_min', 'positive_sentiment_after_pct', 'thread_depth_increase'],
            ],
        ]);
    }

    public function test_editor_dashboard_requires_section_editor_or_above(): void
    {
        $response = $this->actingAs($this->journalist)
            ->getJson('/api/v1/reports/editor/dashboard');

        $response->assertStatus(403);
    }

    public function test_editor_dashboard_team_includes_journalist_status(): void
    {
        $this->createComment('approved');

        $response = $this->actingAs($this->sectionEditor)
            ->getJson('/api/v1/reports/editor/dashboard');

        $response->assertStatus(200);
        $team = $response->json('data.team');
        $this->assertNotEmpty($team);

        foreach ($team as $member) {
            $this->assertContains($member['status'], ['Improving', 'Stable', 'Needs Support']);
        }
    }

    // ── Senior Dashboard ────────────────────────────────────

    public function test_senior_dashboard_returns_expected_structure(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/senior/dashboard');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'publication_health' => ['overall_score', 'trend_vs_last_month', 'hours_saved', 'welfare_flags_count', 'engagement_rate'],
                'section_comparison',
                'best_posts_all',
                'worst_posts_all',
                'welfare_flags',
                'cross_section_risks',
                'engagement_leaderboard',
            ],
        ]);
    }

    public function test_senior_dashboard_requires_senior_editor_or_admin(): void
    {
        $response = $this->actingAs($this->sectionEditor)
            ->getJson('/api/v1/reports/senior/dashboard');

        $response->assertStatus(403);

        $response = $this->actingAs($this->seniorEditor)
            ->getJson('/api/v1/reports/senior/dashboard');

        $response->assertStatus(200);
    }

    public function test_senior_dashboard_welfare_flags_threshold(): void
    {
        // Create 51 personal attacks against journalist's articles
        for ($i = 0; $i < 51; $i++) {
            $this->createComment('approved', [
                'is_personal_attack' => true,
                'created_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/senior/dashboard');

        $response->assertStatus(200);
        $welfareFlags = $response->json('data.welfare_flags');
        $this->assertNotEmpty($welfareFlags);
        $this->assertGreaterThanOrEqual(51, $welfareFlags[0]['personal_attacks_this_month']);
    }

    public function test_senior_dashboard_best_posts_include_section(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/senior/dashboard');

        $response->assertStatus(200);
        $bestPosts = $response->json('data.best_posts_all');

        if (! empty($bestPosts)) {
            $this->assertArrayHasKey('section', $bestPosts[0]);
            $this->assertArrayHasKey('success_factor', $bestPosts[0]);
        }
    }

    public function test_senior_dashboard_worst_posts_include_root_cause(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/senior/dashboard');

        $response->assertStatus(200);
        $worstPosts = $response->json('data.worst_posts_all');

        if (! empty($worstPosts)) {
            $this->assertArrayHasKey('root_cause_flag', $worstPosts[0]);
            $this->assertArrayHasKey('suggestion_offered', $worstPosts[0]);
            $this->assertArrayHasKey('suggestion_accepted', $worstPosts[0]);
        }
    }

    // ── Creator Dashboard ───────────────────────────────────

    public function test_creator_dashboard_returns_expected_structure(): void
    {
        $response = $this->actingAs($this->creator)
            ->getJson('/api/v1/reports/creator/dashboard');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'positive_summary' => ['positive_pct', 'top_positive_themes'],
                'handled_summary' => ['handled_count'],
                'engagement_summary' => ['responses_sent_this_week', 'community_responses'],
                'community_highlights',
                'surge_status',
                'weekly_digest',
            ],
        ]);
    }

    public function test_creator_dashboard_requires_creator_role(): void
    {
        $response = $this->actingAs($this->journalist)
            ->getJson('/api/v1/reports/creator/dashboard');

        $response->assertStatus(403);
    }

    public function test_creator_dashboard_positive_pct_is_integer(): void
    {
        $response = $this->actingAs($this->creator)
            ->getJson('/api/v1/reports/creator/dashboard');

        $response->assertStatus(200);
        $positivePct = $response->json('data.positive_summary.positive_pct');
        $this->assertIsInt($positivePct);
    }

    public function test_creator_dashboard_handled_summary_has_no_breakdown(): void
    {
        $response = $this->actingAs($this->creator)
            ->getJson('/api/v1/reports/creator/dashboard');

        $response->assertStatus(200);
        $handledSummary = $response->json('data.handled_summary');
        $this->assertArrayHasKey('handled_count', $handledSummary);
        $this->assertCount(1, $handledSummary); // ONLY handled_count, nothing else
    }

    public function test_creator_dashboard_no_toxic_content_exposed(): void
    {
        $response = $this->actingAs($this->creator)
            ->getJson('/api/v1/reports/creator/dashboard');

        $response->assertStatus(200);
        $data = $response->json('data');

        // No toxicity_score, no original_text of deleted comments, no breakdown
        $this->assertArrayNotHasKey('toxicity_score', $data);
        $this->assertArrayNotHasKey('deleted_breakdown', $data);
        $this->assertArrayNotHasKey('hidden_breakdown', $data);
    }

    public function test_creator_dashboard_surge_status_null_when_no_active(): void
    {
        $response = $this->actingAs($this->creator)
            ->getJson('/api/v1/reports/creator/dashboard');

        $response->assertStatus(200);
        $this->assertNull($response->json('data.surge_status'));
    }

    public function test_creator_dashboard_surge_status_with_active_event(): void
    {
        SurgeEvent::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'creator_id' => $this->creator->id,
            'post_platform_id' => 'post_123',
            'detected_at' => now(),
            'comment_rate_multiplier' => 3.5,
            'negative_sentiment_pct' => 65.0,
            'new_account_pct' => 20.0,
            'coordinated_phrases' => ['go away'],
            'action_taken' => 'alert',
            'creator_notified' => true,
            'creator_stepped_away' => false,
            'resolved_at' => null,
        ]);

        $response = $this->actingAs($this->creator)
            ->getJson('/api/v1/reports/creator/dashboard');

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.surge_status'));
    }

    // ── Monthly Export ──────────────────────────────────────

    public function test_monthly_export_requires_senior_editor_or_admin(): void
    {
        $response = $this->actingAs($this->sectionEditor)
            ->postJson('/api/v1/reports/monthly-export');

        $response->assertStatus(403);
    }

    public function test_monthly_export_generates_pdf(): void
    {
        Storage::fake('s3');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/reports/monthly-export');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['url', 'expires_in']]);

        $year = now()->format('Y');
        $month = now()->format('m');
        Storage::disk('s3')->assertExists("reports/test-publisher/{$year}-{$month}.pdf");
    }

    // ── Caching ─────────────────────────────────────────────

    public function test_journalist_dashboard_is_cached(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturnSelf();
        Cache::shouldReceive('remember')
            ->once()
            ->andReturn([
                'stats' => ['comments_today' => 0, 'auto_actioned_today' => 0, 'queue_count' => 0, 'tidemark_score' => 100],
                'trend' => [],
                'articles_today' => collect(),
                'engagement_summary' => ['drafts_today' => 0, 'awaiting_approval' => 0, 'auto_posted_today' => 0],
            ]);

        $response = $this->actingAs($this->journalist)
            ->getJson('/api/v1/reports/journalist/dashboard');

        $response->assertStatus(200);
    }

    // ── Cross-role access ───────────────────────────────────

    public function test_admin_can_access_all_dashboards(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/journalist/dashboard');
        $response->assertStatus(200);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/editor/dashboard');
        $response->assertStatus(200);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/senior/dashboard');
        $response->assertStatus(200);
    }

    // ── root_cause_flag & success_factor values ─────────────

    public function test_success_factor_values_are_valid(): void
    {
        $validFactors = [
            'achievement_framing', 'neutral_headline', 'analytical_angle',
            'strong_engagement_response', 'local_interest', 'positive_outcome',
        ];

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/senior/dashboard');

        $response->assertStatus(200);

        foreach ($response->json('data.best_posts_all') ?? [] as $post) {
            $this->assertContains($post['success_factor'], $validFactors);
        }
    }

    public function test_root_cause_flag_values_are_valid(): void
    {
        $validFlags = [
            'comparative_framing', 'clickbait_headline', 'sensitive_topic',
            'breaking_news_spike', 'scheduling_pattern', 'repeat_offender_audience',
            null,
        ];

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/senior/dashboard');

        $response->assertStatus(200);

        foreach ($response->json('data.worst_posts_all') ?? [] as $post) {
            $this->assertContains($post['root_cause_flag'], $validFlags);
        }
    }
}
