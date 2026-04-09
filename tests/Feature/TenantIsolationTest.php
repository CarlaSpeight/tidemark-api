<?php

namespace Tests\Feature;

use App\Http\Middleware\AuditLogger;
use App\Http\Middleware\TenantIsolation;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $bbcTenant;

    private Tenant $guardianTenant;

    private User $bbcJournalist;

    private User $guardianUser;

    private Article $guardianArticle;

    private Comment $guardianComment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        // BBC tenant + journalist
        $this->bbcTenant = Tenant::create([
            'name' => 'BBC Test',
            'slug' => 'bbc-test',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $this->bbcJournalist = User::factory()->create([
            'tenant_id' => $this->bbcTenant->id,
            'role' => 'journalist',
            'section' => 'Sport',
        ]);
        $this->bbcJournalist->assignRole('journalist');

        // Guardian tenant + user + article + comment
        $this->guardianTenant = Tenant::create([
            'name' => 'Guardian Test',
            'slug' => 'guardian-test',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $this->guardianUser = User::factory()->create([
            'tenant_id' => $this->guardianTenant->id,
            'role' => 'journalist',
            'section' => 'News',
        ]);

        $this->guardianArticle = Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->guardianTenant->id,
            'journalist_id' => $this->guardianUser->id,
            'title' => 'Guardian Article',
            'url' => 'https://guardian.test/article',
            'platform' => 'website',
            'topic_category' => 'news',
            'sensitivity_level' => 'low',
            'comments_enabled' => true,
            'published_at' => now(),
        ]);

        $this->guardianComment = Comment::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->guardianTenant->id,
            'article_id' => $this->guardianArticle->id,
            'platform' => 'website',
            'original_text' => 'A Guardian comment',
            'normalised_text' => 'a guardian comment',
            'commenter_platform_id' => '12345',
            'commenter_display_name' => 'Commenter',
            'toxicity_score' => 10,
            'confidence_score' => 95,
            'status' => 'approved',
            'routing_decision' => 'approve',
        ]);

        // Register lightweight test routes that bind models and apply the middleware
        Route::middleware([
            'auth:sanctum',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            AuditLogger::class,
            TenantIsolation::class,
        ])
            ->prefix('api/v1/test')
            ->group(function () {
                Route::get('/comments/{comment}', fn (Comment $comment) => response()->json(['id' => $comment->id]));
                Route::post('/comments/{comment}', fn (Comment $comment) => response()->json(['id' => $comment->id]));
                Route::delete('/comments/{comment}', fn (Comment $comment) => response()->json(['id' => $comment->id]));
                Route::get('/articles/{article}', fn (Article $article) => response()->json(['id' => $article->id]));
                Route::post('/articles/{article}', fn (Article $article) => response()->json(['id' => $article->id]));
                Route::delete('/articles/{article}', fn (Article $article) => response()->json(['id' => $article->id]));
            });
    }

    public function test_get_guardian_comment_returns_403(): void
    {
        $response = $this->actingAs($this->bbcJournalist, 'sanctum')
            ->getJson("/api/v1/test/comments/{$this->guardianComment->id}");

        $response->assertStatus(403);
        $this->assertSecurityViolationLogged();
    }

    public function test_post_guardian_comment_returns_403(): void
    {
        $response = $this->actingAs($this->bbcJournalist, 'sanctum')
            ->postJson("/api/v1/test/comments/{$this->guardianComment->id}");

        $response->assertStatus(403);
        $this->assertSecurityViolationLogged();
    }

    public function test_delete_guardian_comment_returns_403(): void
    {
        $response = $this->actingAs($this->bbcJournalist, 'sanctum')
            ->deleteJson("/api/v1/test/comments/{$this->guardianComment->id}");

        $response->assertStatus(403);
        $this->assertSecurityViolationLogged();
    }

    public function test_get_guardian_article_returns_403(): void
    {
        $response = $this->actingAs($this->bbcJournalist, 'sanctum')
            ->getJson("/api/v1/test/articles/{$this->guardianArticle->id}");

        $response->assertStatus(403);
        $this->assertSecurityViolationLogged();
    }

    public function test_post_guardian_article_returns_403(): void
    {
        $response = $this->actingAs($this->bbcJournalist, 'sanctum')
            ->postJson("/api/v1/test/articles/{$this->guardianArticle->id}");

        $response->assertStatus(403);
        $this->assertSecurityViolationLogged();
    }

    public function test_delete_guardian_article_returns_403(): void
    {
        $response = $this->actingAs($this->bbcJournalist, 'sanctum')
            ->deleteJson("/api/v1/test/articles/{$this->guardianArticle->id}");

        $response->assertStatus(403);
        $this->assertSecurityViolationLogged();
    }

    public function test_cross_tenant_never_returns_404(): void
    {
        $responses = [
            $this->actingAs($this->bbcJournalist, 'sanctum')
                ->getJson("/api/v1/test/comments/{$this->guardianComment->id}"),
            $this->actingAs($this->bbcJournalist, 'sanctum')
                ->getJson("/api/v1/test/articles/{$this->guardianArticle->id}"),
        ];

        foreach ($responses as $response) {
            $this->assertNotEquals(404, $response->status(), 'Cross-tenant access must never return 404');
        }
    }

    public function test_same_tenant_access_succeeds(): void
    {
        $bbcArticle = Article::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->bbcTenant->id,
            'journalist_id' => $this->bbcJournalist->id,
            'title' => 'BBC Article',
            'url' => 'https://bbc.test/article',
            'platform' => 'website',
            'topic_category' => 'news',
            'sensitivity_level' => 'low',
            'comments_enabled' => true,
            'published_at' => now(),
        ]);

        $response = $this->actingAs($this->bbcJournalist, 'sanctum')
            ->getJson("/api/v1/test/articles/{$bbcArticle->id}");

        $response->assertStatus(200);
    }

    private function assertSecurityViolationLogged(): void
    {
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->bbcJournalist->id,
            'event' => 'security_violation',
        ]);

        // Clean up for next assertion
        AuditLog::where('event', 'security_violation')->delete();
    }
}
