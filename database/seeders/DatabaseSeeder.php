<?php

namespace Database\Seeders;

use App\Models\AbTest;
use App\Models\Article;
use App\Models\ArticleStat;
use App\Models\AutoResponseRule;
use App\Models\Comment;
use App\Models\CommunityHighlight;
use App\Models\CreatorProfile;
use App\Models\EngagementResponse;
use App\Models\ModerationAction;
use App\Models\PrescoreRequest;
use App\Models\SocialConnection;
use App\Models\SurgeEvent;
use App\Models\Tenant;
use App\Models\ToneOfVoiceProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // ── Tenants ──────────────────────────────────────────
        $mediaTenant = Tenant::create([
            'name' => 'BBC Sport Digital',
            'slug' => 'bbc-sport',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
        ]);

        $creatorTenant = Tenant::create([
            'name' => 'Creator Demo',
            'slug' => 'creator-demo',
            'subscription_tier' => 'creator_pro',
            'product_tier' => 'creator',
            'is_active' => true,
        ]);

        // ── Media Users (8) ─────────────────────────────────
        $admin = User::factory()->create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'Admin User',
            'email' => 'admin@tidemark.media',
            'role' => 'super_admin',
        ]);
        $admin->assignRole('super_admin');

        $seniorEditor = User::factory()->create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'Sarah Jenkins',
            'email' => 'sarah.jenkins@tidemark.media',
            'role' => 'editor',
            'section' => 'Football',
        ]);
        $seniorEditor->assignRole('editor');

        $sectionEditors = collect();
        $sections = ['Football', 'Cricket', 'Rugby', 'Tennis'];
        foreach ($sections as $section) {
            $editor = User::factory()->create([
                'tenant_id' => $mediaTenant->id,
                'role' => 'deputy_editor',
                'section' => $section,
                'email' => strtolower(str_replace(' ', '.', fake()->name())) . '@tidemark.media',
            ]);
            $editor->assignRole('deputy_editor');
            $sectionEditors->push($editor);
        }

        $journalists = collect();
        for ($i = 0; $i < 2; $i++) {
            $journalist = User::factory()->create([
                'tenant_id' => $mediaTenant->id,
                'role' => 'journalist',
                'section' => fake()->randomElement($sections),
                'job_title' => 'Sports Correspondent',
                'email' => strtolower(str_replace(' ', '.', fake()->name())) . '@tidemark.media',
            ]);
            $journalist->assignRole('journalist');
            $journalists->push($journalist);
        }

        $allMediaUsers = collect([$admin, $seniorEditor])
            ->merge($sectionEditors)
            ->merge($journalists);

        // ── Creator Users (2) ───────────────────────────────
        $creator = User::factory()->create([
            'tenant_id' => $creatorTenant->id,
            'name' => 'Alex Taylor',
            'email' => 'alex@tidemark.media',
            'role' => 'creator',
        ]);
        $creator->assignRole('creator');

        $creatorManager = User::factory()->create([
            'tenant_id' => $creatorTenant->id,
            'name' => 'Jordan Manager',
            'email' => 'jordan@tidemark.media',
            'role' => 'agent',
        ]);
        $creatorManager->assignRole('agent');

        // ── Social Connections ──────────────────────────────
        $platforms = ['website', 'facebook', 'instagram', 'twitter', 'youtube'];
        foreach ($platforms as $platform) {
            SocialConnection::create([
                'tenant_id' => $mediaTenant->id,
                'platform' => $platform,
                'platform_page_id' => fake()->numerify('page_########'),
                'platform_page_name' => 'BBC Sport ' . ucfirst($platform),
                'access_token' => fake()->sha256(),
                'refresh_token' => fake()->sha256(),
                'token_expires_at' => now()->addDays(60),
                'is_active' => true,
                'needs_reauth' => false,
            ]);
        }

        SocialConnection::create([
            'tenant_id' => $creatorTenant->id,
            'platform' => 'instagram',
            'platform_page_id' => fake()->numerify('page_########'),
            'platform_page_name' => 'Alex Taylor IG',
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
            'token_expires_at' => now()->addDays(30),
            'is_active' => true,
            'needs_reauth' => false,
        ]);

        // ── Tone of Voice Profile ───────────────────────────
        $toneProfile = ToneOfVoiceProfile::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'BBC Sport Standard',
            'formality_level' => 'formal',
            'personality_traits' => ['warm', 'knowledgeable', 'community-focused'],
            'topics_to_avoid' => ['gambling', 'politics'],
            'rival_brands_to_avoid' => ['Sky Sports', 'BT Sport'],
            'example_responses' => [
                'Thanks for your comment! Our reporter covers this in more detail here.',
                'Great point – we\'ve updated the article to reflect this.',
                'We understand your frustration. Our editorial team reviews all feedback.',
            ],
            'custom_instructions' => 'Always remain impartial. Never take sides in fan debates. Acknowledge factual errors promptly.',
            'is_active' => true,
            'auto_response_enabled' => true,
            'auto_response_types' => ['factual_answer', 'positive_acknowledgement'],
        ]);

        AutoResponseRule::create([
            'tenant_id' => $mediaTenant->id,
            'tone_profile_id' => $toneProfile->id,
            'rule_name' => 'Factual correction thanks',
            'trigger_pattern' => ['keywords' => ['correction', 'typo', 'wrong score']],
            'response_template' => 'Thanks for flagging – we\'ve updated the article.',
            'use_ai_generation' => false,
            'is_active' => true,
            'times_triggered' => 42,
        ]);

        // ── Articles (200) ──────────────────────────────────
        $articles = Article::factory()
            ->count(200)
            ->sequence(fn ($seq) => [
                'tenant_id' => $mediaTenant->id,
                'journalist_id' => $allMediaUsers->random()->id,
                'platform' => fake()->randomElement(['website', 'facebook', 'twitter', 'youtube']),
            ])
            ->create();

        // ── Comments (5000) ─────────────────────────────────
        $statusWeights = [
            'approved' => 60,
            'hidden' => 15,
            'pending' => 10,
            'confirmed_deleted' => 5,
            'deleted' => 5,
            'restored' => 5,
        ];
        $statuses = [];
        foreach ($statusWeights as $status => $weight) {
            $statuses = array_merge($statuses, array_fill(0, $weight, $status));
        }

        $comments = Comment::factory()
            ->count(5000)
            ->sequence(fn ($seq) => [
                'tenant_id' => $mediaTenant->id,
                'article_id' => $articles->random()->id,
                'status' => $statuses[array_rand($statuses)],
            ])
            ->create();

        // ── Moderation Actions (for ~500 hidden/deleted comments) ─
        $moderatedComments = $comments->whereIn('status', ['hidden', 'confirmed_deleted', 'deleted', 'restored'])->take(500);
        $moderators = $allMediaUsers->filter(fn ($u) => in_array($u->role, ['deputy_editor', 'editor', 'super_admin']));

        foreach ($moderatedComments as $comment) {
            ModerationAction::create([
                'tenant_id' => $mediaTenant->id,
                'comment_id' => $comment->id,
                'moderator_id' => $moderators->random()->id,
                'action' => match ($comment->status) {
                    'hidden' => 'hide',
                    'confirmed_deleted' => 'confirm_delete',
                    'deleted' => 'delete',
                    'restored' => 'restore',
                    default => 'approve',
                },
                'reason' => fake()->randomElement(['toxicity_threshold', 'personal_attack', 'off_topic', 'spam', 'manual_review']),
                'notes' => fake()->optional(0.3)->sentence(),
            ]);
        }

        // ── Engagement Responses (for ~200 approved comments) ─
        $engagementComments = $comments->where('status', 'approved')->take(200);
        foreach ($engagementComments as $comment) {
            EngagementResponse::create([
                'tenant_id' => $mediaTenant->id,
                'comment_id' => $comment->id,
                'article_id' => $comment->article_id,
                'platform' => $comment->platform,
                'draft_text' => fake()->sentence(),
                'final_text' => fake()->optional(0.8)->sentence(),
                'response_type' => fake()->randomElement(['factual_answer', 'positive_acknowledgement', 'debate_prompt', 'complaint_empathy']),
                'tone_profile_id' => $toneProfile->id,
                'status' => fake()->randomElement(['draft', 'approved', 'posted', 'auto_posted']),
                'drafted_by_ai' => fake()->boolean(80),
                'approved_by' => $moderators->random()->id,
                'approved_at' => fake()->optional(0.7)->dateTimeBetween('-7 days'),
                'posted_at' => fake()->optional(0.5)->dateTimeBetween('-7 days'),
            ]);
        }

        // ── Article Stats ───────────────────────────────────
        foreach ($articles as $article) {
            $articleComments = $comments->where('article_id', $article->id);
            $total = $articleComments->count();

            ArticleStat::create([
                'article_id' => $article->id,
                'tenant_id' => $mediaTenant->id,
                'total_comments' => $total,
                'auto_approved' => (int) ($total * 0.6),
                'auto_actioned' => (int) ($total * 0.2),
                'queued' => (int) ($total * 0.1),
                'engagement_responses_sent' => fake()->numberBetween(0, max(1, (int) ($total * 0.1))),
                'avg_toxicity_score' => fake()->numberBetween(15, 55),
                'sentiment_positive' => fake()->randomFloat(2, 0.3, 0.7),
                'calculated_at' => now(),
            ]);
        }

        // ── Prescore Requests (15) ──────────────────────────
        for ($i = 0; $i < 15; $i++) {
            PrescoreRequest::create([
                'tenant_id' => $mediaTenant->id,
                'journalist_id' => $journalists->random()->id,
                'headline' => fake()->sentence(8),
                'caption' => fake()->optional(0.5)->sentence(),
                'topic_category' => fake()->randomElement(['news', 'opinion', 'analysis', 'live', 'feature']),
                'predicted_score' => fake()->numberBetween(10, 90),
                'risk_level' => fake()->randomElement(['low', 'medium', 'high']),
                'suggestions' => [
                    fake()->sentence(),
                    fake()->sentence(),
                ],
                'accepted_suggestion' => fake()->optional(0.4)->sentence(),
                'accepted_at' => fake()->optional(0.4)->dateTimeBetween('-14 days'),
            ]);
        }

        // ── AB Tests (10) ───────────────────────────────────
        foreach ($articles->take(10) as $article) {
            AbTest::create([
                'tenant_id' => $mediaTenant->id,
                'article_id' => $article->id,
                'version_a_headline' => fake()->sentence(8),
                'version_b_headline' => fake()->sentence(8),
                'version_a_score' => fake()->optional(0.7)->numberBetween(20, 90),
                'version_b_score' => fake()->optional(0.7)->numberBetween(20, 90),
                'winner' => fake()->optional(0.5)->randomElement(['a', 'b']),
                'concluded_at' => fake()->optional(0.5)->dateTimeBetween('-7 days'),
            ]);
        }

        // ── Creator Profile ─────────────────────────────────
        $creatorProfile = CreatorProfile::create([
            'tenant_id' => $creatorTenant->id,
            'user_id' => $creator->id,
            'display_name' => 'Alex Taylor',
            'platform_handles' => [
                'instagram' => '@alextaylor',
                'tiktok' => '@alextaylor_tt',
                'youtube' => 'AlexTaylorVlogs',
            ],
            'content_topics' => ['lifestyle', 'travel', 'fitness'],
            'vibe_shield_level' => 'protected',
            'allow_body_comments' => false,
            'allow_relationship_comments' => false,
            'allow_success_shaming' => false,
            'custom_protection_rules' => [
                'Block any reference to home address',
                'Flag comments mentioning family members',
            ],
            'manager_user_id' => $creatorManager->id,
            'weekly_digest_enabled' => true,
            'surge_alert_threshold_multiplier' => 10.0,
        ]);

        // ── Creator Surge Event ─────────────────────────────
        SurgeEvent::create([
            'tenant_id' => $creatorTenant->id,
            'creator_id' => $creator->id,
            'post_platform_id' => 'ig_post_12345',
            'detected_at' => now()->subHours(6),
            'comment_rate_multiplier' => 4.50,
            'negative_sentiment_pct' => 38.20,
            'new_account_pct' => 22.50,
            'coordinated_phrases' => ['cancel', 'disgusting', 'unfollow'],
            'action_taken' => 'paused_comments',
            'creator_notified' => true,
            'creator_stepped_away' => false,
            'resolved_at' => now()->subHours(3),
        ]);

        // ── Community Highlights (5) ────────────────────────
        $approvedComments = $comments->where('status', 'approved')->take(5);
        foreach ($approvedComments as $comment) {
            CommunityHighlight::create([
                'tenant_id' => $creatorTenant->id,
                'creator_id' => $creator->id,
                'comment_id' => $comment->id,
                'highlight_type' => fake()->randomElement(['impactful', 'loyal_community', 'funny', 'constructive']),
                'auto_selected' => true,
                'pinned' => fake()->boolean(20),
                'saved' => fake()->boolean(30),
            ]);
        }

        $this->command->info('Seeded: 2 tenants, 10 users, 200 articles, 5000 comments, article stats, engagement responses, moderation actions, prescore requests, AB tests, creator profile, surge event, community highlights.');
    }
}
