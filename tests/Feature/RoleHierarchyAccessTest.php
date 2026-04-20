<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Comment;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleHierarchyAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_super_admin_platform_stats_include_cross_tenant_data(): void
    {
        $tenantA = Tenant::factory()->create(['product_tier' => 'media']);
        $tenantB = Tenant::factory()->create(['product_tier' => 'creator']);

        $superAdmin = $this->makeUser($tenantA, 'super_admin');

        $journalistA = $this->makeUser($tenantA, 'journalist');
        $journalistB = $this->makeUser($tenantB, 'journalist');

        $articleA = Article::factory()->create([
            'tenant_id' => $tenantA->id,
            'journalist_id' => $journalistA->id,
        ]);
        $articleB = Article::factory()->create([
            'tenant_id' => $tenantB->id,
            'journalist_id' => $journalistB->id,
        ]);

        Comment::factory()->create([
            'tenant_id' => $tenantA->id,
            'article_id' => $articleA->id,
            'platform' => 'website',
            'created_at' => now(),
        ]);
        Comment::factory()->create([
            'tenant_id' => $tenantB->id,
            'article_id' => $articleB->id,
            'platform' => 'website',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/platform-stats')
            ->assertOk()
            ->assertJsonPath('data.active_tenants', 2)
            ->assertJsonPath('data.comments_today', 2)
            ->assertJsonPath('data.total_users', 3)
            ->assertJsonPath('data.publication_tenants', 1)
            ->assertJsonPath('data.creator_tenants', 1)
            ->assertJsonPath('data.tidemark_staff_users', 1);
    }

    public function test_cs_agent_is_read_only_for_moderation_actions(): void
    {
        $tenant = Tenant::factory()->create(['product_tier' => 'media']);
        $csAgent = $this->makeUser($tenant, 'cs_agent');
        $journalist = $this->makeUser($tenant, 'journalist');

        $article = Article::factory()->create([
            'tenant_id' => $tenant->id,
            'journalist_id' => $journalist->id,
        ]);

        $comment = Comment::factory()->create([
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'platform' => 'website',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($csAgent);

        $this->postJson('/api/v1/moderation/comments/' . $comment->id . '/hide')
            ->assertForbidden();
    }

    public function test_friends_family_can_view_but_cannot_moderate(): void
    {
        $tenant = Tenant::factory()->create(['product_tier' => 'media']);
        $friendsFamily = $this->makeUser($tenant, 'friends_family');
        $journalist = $this->makeUser($tenant, 'journalist');

        $article = Article::factory()->create([
            'tenant_id' => $tenant->id,
            'journalist_id' => $journalist->id,
        ]);

        $comment = Comment::factory()->create([
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'platform' => 'website',
            'status' => 'hidden',
        ]);

        Sanctum::actingAs($friendsFamily);

        $this->getJson('/api/v1/moderation/hidden-library')->assertOk();

        $this->postJson('/api/v1/moderation/comments/' . $comment->id . '/hide')
            ->assertForbidden();
    }

    public function test_editor_has_team_management_permissions_but_journalist_does_not(): void
    {
        $tenant = Tenant::factory()->create(['product_tier' => 'media']);

        $editor = $this->makeUser($tenant, 'editor');
        $journalist = $this->makeUser($tenant, 'journalist');

        $this->assertTrue($editor->can('users.create'));
        $this->assertTrue($editor->can('users.update'));

        $this->assertFalse($journalist->can('users.create'));
        $this->assertFalse($journalist->can('users.update'));
    }

    private function makeUser(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => $role,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
