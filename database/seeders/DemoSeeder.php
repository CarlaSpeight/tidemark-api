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
use App\Models\WellbeingDigest;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'TidemarkDemo2026!';

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // ═══════════════════════════════════════════════════════
        //  TENANT 1 — BBC Sport Digital (Media tier)
        // ═══════════════════════════════════════════════════════
        $mediaTenant = Tenant::create([
            'name' => 'BBC Sport Digital',
            'slug' => 'bbc-sport',
            'subscription_tier' => 'enterprise',
            'product_tier' => 'media',
            'is_active' => true,
            'settings' => [
                'auto_moderate_threshold' => 75,
                'prescore_enabled' => true,
                'engagement_enabled' => true,
            ],
        ]);

        // ── Media Users (8): 1 admin, 1 senior_editor, 2 section_editors, 4 journalists ──
        $password = Hash::make(self::DEMO_PASSWORD);

        $admin = User::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'James Barrett',
            'email' => 'james.barrett@bbc.co.uk',
            'password' => $password,
            'role' => 'admin',
            'section' => null,
            'job_title' => 'Head of Digital',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $seniorEditor = User::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'Rachel Williams',
            'email' => 'rachel.williams@bbc.co.uk',
            'password' => $password,
            'role' => 'senior_editor',
            'section' => 'Sport',
            'job_title' => 'Senior Sports Editor',
            'is_active' => true,
        ]);
        $seniorEditor->assignRole('senior_editor');

        $sectionEditor1 = User::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'Mark Thompson',
            'email' => 'mark.thompson@bbc.co.uk',
            'password' => $password,
            'role' => 'section_editor',
            'section' => 'Football',
            'job_title' => 'Football Editor',
            'is_active' => true,
        ]);
        $sectionEditor1->assignRole('section_editor');

        $sectionEditor2 = User::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'Lisa Chen',
            'email' => 'lisa.chen@bbc.co.uk',
            'password' => $password,
            'role' => 'section_editor',
            'section' => 'Cricket',
            'job_title' => 'Cricket Editor',
            'is_active' => true,
        ]);
        $sectionEditor2->assignRole('section_editor');

        // Journalists — Sarah Jones & Priya Sharma get welfare flags (>50 personal attacks each)
        $sarahJones = User::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'Sarah Jones',
            'email' => 'sarah.jones@bbc.co.uk',
            'password' => $password,
            'role' => 'journalist',
            'section' => 'Football',
            'job_title' => 'Football Correspondent',
            'is_active' => true,
        ]);
        $sarahJones->assignRole('journalist');

        $priyaSharma = User::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'Priya Sharma',
            'email' => 'priya.sharma@bbc.co.uk',
            'password' => $password,
            'role' => 'journalist',
            'section' => 'Cricket',
            'job_title' => 'Cricket Correspondent',
            'is_active' => true,
        ]);
        $priyaSharma->assignRole('journalist');

        $tomHughes = User::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'Tom Hughes',
            'email' => 'tom.hughes@bbc.co.uk',
            'password' => $password,
            'role' => 'journalist',
            'section' => 'Rugby',
            'job_title' => 'Rugby Correspondent',
            'is_active' => true,
        ]);
        $tomHughes->assignRole('journalist');

        $emmaKnight = User::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'Emma Knight',
            'email' => 'emma.knight@bbc.co.uk',
            'password' => $password,
            'role' => 'journalist',
            'section' => 'Tennis',
            'job_title' => 'Tennis Correspondent',
            'is_active' => true,
        ]);
        $emmaKnight->assignRole('journalist');

        $moderators = collect([$admin, $seniorEditor, $sectionEditor1, $sectionEditor2]);
        $journalists = collect([$sarahJones, $priyaSharma, $tomHughes, $emmaKnight]);
        $allMediaUsers = $moderators->merge($journalists);

        // ── Social Connections ──────────────────────────────
        foreach (['website', 'facebook', 'instagram', 'twitter', 'youtube'] as $platform) {
            SocialConnection::create([
                'tenant_id' => $mediaTenant->id,
                'platform' => $platform,
                'platform_page_id' => 'bbc_sport_' . $platform,
                'platform_page_name' => 'BBC Sport ' . ucfirst($platform),
                'access_token' => 'demo_token_' . $platform,
                'refresh_token' => 'demo_refresh_' . $platform,
                'token_expires_at' => now()->addDays(90),
                'is_active' => true,
                'needs_reauth' => false,
            ]);
        }

        // ── Tone of Voice Profile ───────────────────────────
        $toneProfile = ToneOfVoiceProfile::create([
            'tenant_id' => $mediaTenant->id,
            'name' => 'BBC Sport Standard',
            'formality_level' => 'formal',
            'personality_traits' => ['warm', 'knowledgeable', 'impartial', 'community-focused'],
            'topics_to_avoid' => ['gambling', 'politics', 'religion'],
            'rival_brands_to_avoid' => ['Sky Sports', 'BT Sport', 'TNT Sports'],
            'example_responses' => [
                'Thanks for your comment! Our reporter covers this in more detail in the updated article.',
                'Great point — we\'ve corrected the article to reflect this. Thanks for flagging.',
                'We understand your frustration. Our editorial team takes all feedback seriously.',
                'Interesting perspective! What do other fans think?',
            ],
            'custom_instructions' => 'Always remain impartial. Never take sides in fan debates. Acknowledge factual errors promptly. Use British English spelling.',
            'is_active' => true,
            'auto_response_enabled' => true,
            'auto_response_types' => ['factual_answer', 'positive_acknowledgement'],
        ]);

        AutoResponseRule::create([
            'tenant_id' => $mediaTenant->id,
            'tone_profile_id' => $toneProfile->id,
            'rule_name' => 'Factual correction thanks',
            'trigger_pattern' => ['keywords' => ['correction', 'typo', 'wrong score', 'wrong name']],
            'response_template' => 'Thanks for flagging — we\'ve updated the article.',
            'use_ai_generation' => false,
            'is_active' => true,
            'times_triggered' => 42,
        ]);

        AutoResponseRule::create([
            'tenant_id' => $mediaTenant->id,
            'tone_profile_id' => $toneProfile->id,
            'rule_name' => 'Positive fan engagement',
            'trigger_pattern' => ['keywords' => ['great article', 'well written', 'good coverage']],
            'response_template' => null,
            'use_ai_generation' => true,
            'is_active' => true,
            'times_triggered' => 87,
        ]);

        // ── Articles (200) — spread Jan–Jun 2026, realistic BBC Sport headlines ──
        $sportHeadlines = $this->generateSportHeadlines();
        $sections = ['Football', 'Cricket', 'Rugby', 'Tennis'];
        $platforms = ['website', 'facebook', 'twitter', 'youtube'];

        $articles = collect();
        foreach ($sportHeadlines as $i => $headline) {
            // Spread evenly across Jan–Jun 2026
            $publishedAt = Carbon::create(2026, 1, 1)->addDays(intval($i * (180 / 200)));
            $section = $sections[$i % 4];
            $journalistForSection = match ($section) {
                'Football' => $sarahJones,
                'Cricket' => $priyaSharma,
                'Rugby' => $tomHughes,
                'Tennis' => $emmaKnight,
            };

            $articles->push(Article::create([
                'tenant_id' => $mediaTenant->id,
                'journalist_id' => $journalistForSection->id,
                'title' => $headline,
                'url' => 'https://www.bbc.co.uk/sport/' . strtolower($section) . '/article-' . ($i + 1),
                'platform' => $platforms[$i % 4],
                'platform_post_id' => 'bbc_' . ($i + 1),
                'published_at' => $publishedAt,
                'subject_entities' => $this->entitiesForSection($section),
                'sensitivity_level' => fake()->randomElement(['low', 'low', 'low', 'medium', 'medium', 'high']),
                'topic_category' => strtolower($section),
                'comments_enabled' => true,
            ]));
        }

        // ── Comments (5000) — showing improving trend over 6 months ──
        // Earlier months: higher toxicity. Later months: lower toxicity (Tidemark effect)
        $sarahArticles = $articles->where('journalist_id', $sarahJones->id);
        $priyaArticles = $articles->where('journalist_id', $priyaSharma->id);
        $otherArticles = $articles->whereNotIn('journalist_id', [$sarahJones->id, $priyaSharma->id]);

        $allComments = collect();

        // Sarah Jones articles — 60 personal attacks targeted at her
        foreach ($sarahArticles as $article) {
            $commentCount = fake()->numberBetween(20, 35);
            for ($c = 0; $c < $commentCount; $c++) {
                $allComments->push($this->createComment($mediaTenant, $article));
            }
        }
        // Inject personal attacks targeting Sarah Jones
        $sarahCommentIds = $allComments->where('article_id', '!=', null)
            ->filter(fn ($c) => $sarahArticles->pluck('id')->contains($c->article_id))
            ->shuffle()
            ->take(60);
        foreach ($sarahCommentIds as $comment) {
            $comment->update([
                'is_personal_attack' => true,
                'target_entity' => 'Sarah Jones',
                'toxicity_score' => fake()->numberBetween(80, 98),
                'status' => 'hidden',
                'flagged_reason' => 'personal_attack',
                'hidden_at' => $comment->created_at,
                'hidden_by_ai' => true,
            ]);
        }

        // Priya Sharma articles — 55 personal attacks
        foreach ($priyaArticles as $article) {
            $commentCount = fake()->numberBetween(20, 35);
            for ($c = 0; $c < $commentCount; $c++) {
                $allComments->push($this->createComment($mediaTenant, $article));
            }
        }
        $priyaCommentIds = $allComments
            ->filter(fn ($c) => $priyaArticles->pluck('id')->contains($c->article_id))
            ->shuffle()
            ->take(55);
        foreach ($priyaCommentIds as $comment) {
            $comment->update([
                'is_personal_attack' => true,
                'target_entity' => 'Priya Sharma',
                'toxicity_score' => fake()->numberBetween(80, 98),
                'status' => 'hidden',
                'flagged_reason' => 'personal_attack',
                'hidden_at' => $comment->created_at,
                'hidden_by_ai' => true,
            ]);
        }

        // Remaining articles — fill up to ~5000 total
        $remaining = 5000 - $allComments->count();
        $perArticle = max(1, intval($remaining / $otherArticles->count()));
        foreach ($otherArticles as $article) {
            $count = fake()->numberBetween(max(1, $perArticle - 5), $perArticle + 5);
            for ($c = 0; $c < $count; $c++) {
                $allComments->push($this->createComment($mediaTenant, $article));
            }
        }

        $this->command->info("Created {$allComments->count()} comments for media tenant");

        // ── Moderation Actions (for hidden/deleted comments) ──
        $actionableComments = $allComments->whereIn('status', ['hidden', 'confirmed_deleted', 'deleted', 'restored']);
        foreach ($actionableComments as $comment) {
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
                'reason' => $comment->is_personal_attack
                    ? 'Personal attack detected by AI'
                    : fake()->randomElement(['toxicity_threshold', 'off_topic', 'spam', 'manual_review']),
            ]);
        }

        // ── Engagement Responses (30 total) ─────────────────
        // 12 auto-posted, 8 awaiting approval (draft), 5 edited+approved, 5 rejected
        $engageableComments = $allComments->where('status', 'approved')->shuffle();
        $responseConfigs = array_merge(
            array_fill(0, 12, ['status' => 'auto_posted', 'final' => true, 'approved' => true, 'posted' => true]),
            array_fill(0, 8, ['status' => 'draft', 'final' => false, 'approved' => false, 'posted' => false]),
            array_fill(0, 5, ['status' => 'approved', 'final' => true, 'approved' => true, 'posted' => false]),
            array_fill(0, 5, ['status' => 'rejected', 'final' => false, 'approved' => false, 'posted' => false]),
        );

        foreach ($responseConfigs as $idx => $cfg) {
            $comment = $engageableComments->get($idx);
            if (!$comment) continue;

            EngagementResponse::create([
                'tenant_id' => $mediaTenant->id,
                'comment_id' => $comment->id,
                'article_id' => $comment->article_id,
                'platform' => $comment->platform,
                'draft_text' => fake()->sentence(12),
                'final_text' => $cfg['final'] ? fake()->sentence(12) : null,
                'response_type' => fake()->randomElement(['factual_answer', 'positive_acknowledgement', 'debate_prompt', 'complaint_empathy']),
                'tone_profile_id' => $toneProfile->id,
                'status' => $cfg['status'],
                'drafted_by_ai' => true,
                'approved_by' => $cfg['approved'] ? $moderators->random()->id : null,
                'approved_at' => $cfg['approved'] ? fake()->dateTimeBetween('-14 days', '-1 day') : null,
                'posted_at' => $cfg['posted'] ? fake()->dateTimeBetween('-7 days', '-1 day') : null,
            ]);
        }

        // ── Article Stats ───────────────────────────────────
        foreach ($articles as $article) {
            $articleComments = $allComments->where('article_id', $article->id);
            $total = $articleComments->count();
            if ($total === 0) continue;

            // Show improving trend: earlier articles have higher toxicity
            $monthIndex = Carbon::parse($article->published_at)->month - 1; // 0-5
            $toxicityBase = max(15, 50 - ($monthIndex * 6));
            $sentimentBase = min(0.75, 0.4 + ($monthIndex * 0.07));

            ArticleStat::create([
                'article_id' => $article->id,
                'tenant_id' => $mediaTenant->id,
                'total_comments' => $total,
                'auto_approved' => (int) ($total * fake()->randomFloat(2, 0.55, 0.70)),
                'auto_actioned' => (int) ($total * fake()->randomFloat(2, 0.15, 0.25)),
                'queued' => (int) ($total * fake()->randomFloat(2, 0.05, 0.15)),
                'engagement_responses_sent' => fake()->numberBetween(0, max(1, (int) ($total * 0.08))),
                'avg_toxicity_score' => fake()->numberBetween($toxicityBase - 5, $toxicityBase + 5),
                'sentiment_positive' => fake()->randomFloat(2, $sentimentBase - 0.05, $sentimentBase + 0.05),
                'calculated_at' => $article->published_at,
            ]);
        }

        // ── Prescore Requests (15): 11 accepted, 4 ignored ──
        $prescoreHeadlines = [
            ['headline' => 'Premier League Transfer Window: Who\'s Moving Where?', 'risk' => 'medium', 'score' => 62, 'accepted' => true],
            ['headline' => 'England Cricket: Root Breaks Another Record', 'risk' => 'low', 'score' => 25, 'accepted' => true],
            ['headline' => 'Women\'s Six Nations: England Dominant Again', 'risk' => 'low', 'score' => 18, 'accepted' => true],
            ['headline' => 'Racism in Football: New Report Reveals Scale', 'risk' => 'high', 'score' => 88, 'accepted' => true],
            ['headline' => 'VAR Controversy: Should It Stay or Go?', 'risk' => 'high', 'score' => 82, 'accepted' => true],
            ['headline' => 'Wimbledon 2026: British Hopes Preview', 'risk' => 'low', 'score' => 22, 'accepted' => true],
            ['headline' => 'Liverpool Manager Sacked After Poor Run', 'risk' => 'medium', 'score' => 55, 'accepted' => true],
            ['headline' => 'Olympic Funding Cuts: Athletes Speak Out', 'risk' => 'high', 'score' => 78, 'accepted' => true],
            ['headline' => 'F1: Hamilton\'s Final Season at Ferrari', 'risk' => 'medium', 'score' => 45, 'accepted' => true],
            ['headline' => 'Cricket: IPL Salary Cap Row Escalates', 'risk' => 'medium', 'score' => 52, 'accepted' => true],
            ['headline' => 'Rugby World Cup Qualification Drama', 'risk' => 'low', 'score' => 30, 'accepted' => true],
            ['headline' => 'Breaking: Star Player Arrested', 'risk' => 'high', 'score' => 92, 'accepted' => false],
            ['headline' => 'Football Manager\'s Personal Life Exposed', 'risk' => 'high', 'score' => 90, 'accepted' => false],
            ['headline' => 'Match Preview: Arsenal vs Spurs', 'risk' => 'medium', 'score' => 48, 'accepted' => false],
            ['headline' => 'Weekend Fixtures Round-Up', 'risk' => 'low', 'score' => 15, 'accepted' => false],
        ];

        foreach ($prescoreHeadlines as $ps) {
            PrescoreRequest::create([
                'tenant_id' => $mediaTenant->id,
                'journalist_id' => $journalists->random()->id,
                'headline' => $ps['headline'],
                'caption' => fake()->optional(0.5)->sentence(),
                'topic_category' => fake()->randomElement(['news', 'opinion', 'analysis', 'live', 'feature']),
                'predicted_score' => $ps['score'],
                'risk_level' => $ps['risk'],
                'suggestions' => [
                    'Consider softening the headline to reduce toxicity risk',
                    'Add context to reduce inflammatory reactions',
                ],
                'accepted_suggestion' => $ps['accepted'] ? 'Consider softening the headline to reduce toxicity risk' : null,
                'accepted_at' => $ps['accepted'] ? fake()->dateTimeBetween('-30 days', '-1 day') : null,
            ]);
        }

        // ── AB Tests (10) ───────────────────────────────────
        foreach ($articles->take(10) as $i => $article) {
            AbTest::create([
                'tenant_id' => $mediaTenant->id,
                'article_id' => $article->id,
                'version_a_headline' => $article->title,
                'version_b_headline' => 'ALTERNATIVE: ' . $article->title,
                'version_a_score' => fake()->numberBetween(20, 70),
                'version_b_score' => fake()->numberBetween(20, 70),
                'winner' => $i < 7 ? fake()->randomElement(['a', 'b']) : null,
                'concluded_at' => $i < 7 ? fake()->dateTimeBetween('-14 days', '-1 day') : null,
            ]);
        }

        // ═══════════════════════════════════════════════════════
        //  TENANT 2 — Creator Demo (Creator tier)
        // ═══════════════════════════════════════════════════════
        $creatorTenant = Tenant::create([
            'name' => 'Creator Demo',
            'slug' => 'creator-demo',
            'subscription_tier' => 'creator_pro',
            'product_tier' => 'creator',
            'is_active' => true,
            'settings' => [
                'vibe_shield_enabled' => true,
                'surge_detection_enabled' => true,
            ],
        ]);

        $creator = User::create([
            'tenant_id' => $creatorTenant->id,
            'name' => 'Alex Taylor',
            'email' => 'alex@tidemark.media',
            'password' => $password,
            'role' => 'creator',
            'is_active' => true,
        ]);
        $creator->assignRole('creator');

        $creatorManager = User::create([
            'tenant_id' => $creatorTenant->id,
            'name' => 'Jordan Rivers',
            'email' => 'jordan@tidemark.media',
            'password' => $password,
            'role' => 'creator_manager',
            'is_active' => true,
        ]);
        $creatorManager->assignRole('creator_manager');

        SocialConnection::create([
            'tenant_id' => $creatorTenant->id,
            'platform' => 'instagram',
            'platform_page_id' => 'ig_alextaylor',
            'platform_page_name' => 'Alex Taylor IG',
            'access_token' => 'demo_token_ig_alex',
            'refresh_token' => 'demo_refresh_ig_alex',
            'token_expires_at' => now()->addDays(60),
            'is_active' => true,
            'needs_reauth' => false,
        ]);

        SocialConnection::create([
            'tenant_id' => $creatorTenant->id,
            'platform' => 'tiktok',
            'platform_page_id' => 'tt_alextaylor',
            'platform_page_name' => 'Alex Taylor TikTok',
            'access_token' => 'demo_token_tt_alex',
            'refresh_token' => 'demo_refresh_tt_alex',
            'token_expires_at' => now()->addDays(60),
            'is_active' => true,
            'needs_reauth' => false,
        ]);

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
                'Block any reference to home address or location',
                'Flag comments mentioning family members',
                'Auto-hide weight/appearance comments',
            ],
            'manager_user_id' => $creatorManager->id,
            'weekly_digest_enabled' => true,
            'surge_alert_threshold_multiplier' => 10.0,
        ]);

        // ── Creator Articles (10 posts) ─────────────────────
        $creatorArticles = collect();
        $creatorPosts = [
            'Morning routine that changed my life',
            'Travel vlog: 48 hours in Barcelona',
            'My honest take on intermittent fasting',
            'Apartment tour — finally moved!',
            'Workout split that actually works',
            'Responding to your assumptions about me',
            'What I eat in a day (realistic edition)',
            'Grwm for a brand event',
            'Why I took a break from social media',
            'Q&A: Answering your deepest questions',
        ];

        foreach ($creatorPosts as $idx => $title) {
            $creatorArticles->push(Article::create([
                'tenant_id' => $creatorTenant->id,
                'journalist_id' => $creator->id,
                'title' => $title,
                'url' => null,
                'platform' => $idx % 2 === 0 ? 'instagram' : 'tiktok',
                'platform_post_id' => 'creator_post_' . ($idx + 1),
                'published_at' => Carbon::create(2026, 5, 1)->addDays($idx * 3),
                'subject_entities' => ['Alex Taylor'],
                'sensitivity_level' => $idx === 5 ? 'high' : 'low',
                'topic_category' => 'lifestyle',
                'comments_enabled' => true,
            ]));
        }

        // ── Creator Comments (500) ──────────────────────────
        $creatorComments = collect();
        foreach ($creatorArticles as $article) {
            $count = fake()->numberBetween(40, 60);
            for ($c = 0; $c < $count; $c++) {
                $creatorComments->push($this->createComment($creatorTenant, $article, isCreator: true));
            }
        }

        $this->command->info("Created {$creatorComments->count()} comments for creator tenant");

        // ── Active Surge Event (unresolved) ─────────────────
        SurgeEvent::create([
            'tenant_id' => $creatorTenant->id,
            'creator_id' => $creator->id,
            'post_platform_id' => 'creator_post_6', // "Responding to your assumptions" post
            'detected_at' => now()->subHours(2),
            'comment_rate_multiplier' => 8.50,
            'negative_sentiment_pct' => 62.30,
            'new_account_pct' => 35.10,
            'coordinated_phrases' => ['fake', 'cancelled', 'exposed', 'liar'],
            'action_taken' => 'paused_comments',
            'creator_notified' => true,
            'creator_stepped_away' => false,
            'resolved_at' => null, // Active — not yet resolved
        ]);

        // Past resolved surge
        SurgeEvent::create([
            'tenant_id' => $creatorTenant->id,
            'creator_id' => $creator->id,
            'post_platform_id' => 'creator_post_3',
            'detected_at' => Carbon::create(2026, 5, 10, 14, 0),
            'comment_rate_multiplier' => 4.20,
            'negative_sentiment_pct' => 41.00,
            'new_account_pct' => 18.50,
            'coordinated_phrases' => ['scam', 'unfollow'],
            'action_taken' => 'alert',
            'creator_notified' => true,
            'creator_stepped_away' => true,
            'resolved_at' => Carbon::create(2026, 5, 10, 20, 0),
        ]);

        // ── Community Highlights ────────────────────────────
        $positiveCreatorComments = $creatorComments->where('status', 'approved')->shuffle()->take(8);
        $highlightTypes = ['impactful', 'loyal_community', 'funny', 'constructive'];
        foreach ($positiveCreatorComments as $i => $comment) {
            CommunityHighlight::create([
                'tenant_id' => $creatorTenant->id,
                'creator_id' => $creator->id,
                'comment_id' => $comment->id,
                'highlight_type' => $highlightTypes[$i % 4],
                'auto_selected' => true,
                'pinned' => $i < 2,
                'saved' => $i < 4,
            ]);
        }

        // ── Wellbeing Digest ────────────────────────────────
        WellbeingDigest::create([
            'tenant_id' => $creatorTenant->id,
            'creator_id' => $creator->id,
            'positive_themes' => ['supportive community', 'travel inspiration', 'fitness motivation'],
            'handled_count' => 47,
            'one_highlight' => 'A fan shared how your morning routine video helped them establish healthier habits — it was the most engaged-with positive comment this week!',
            'digest_text' => "Hi Alex! This week your community was buzzing with positivity. 87% of comments were supportive, with fans particularly loving your Barcelona vlog. Your Vibe Shield caught and handled 47 negative comments before they reached you. The community rallied around your fitness content, with several fans sharing their own transformation stories. Keep doing what you're doing!",
            'week_start' => Carbon::create(2026, 5, 19),
            'week_end' => Carbon::create(2026, 5, 25),
        ]);

        WellbeingDigest::create([
            'tenant_id' => $creatorTenant->id,
            'creator_id' => $creator->id,
            'positive_themes' => ['authenticity appreciated', 'mental health awareness', 'loyal fanbase'],
            'handled_count' => 82,
            'one_highlight' => 'Your "Why I took a break" post sparked an incredible conversation about creator mental health — over 30 fans shared their own stories.',
            'digest_text' => "Hi Alex! Tough week traffic-wise after the assumptions video, but your community showed up strong. 82 negative comments were handled by Vibe Shield (your highest week). The silver lining: your break video resonated deeply. Fan loyalty metrics are at an all-time high. Jordan has been briefed on the surge event.",
            'week_start' => Carbon::create(2026, 5, 26),
            'week_end' => Carbon::create(2026, 6, 1),
        ]);

        // ── Creator Article Stats ───────────────────────────
        foreach ($creatorArticles as $article) {
            $articleComments = $creatorComments->where('article_id', $article->id);
            $total = $articleComments->count();
            if ($total === 0) continue;

            ArticleStat::create([
                'article_id' => $article->id,
                'tenant_id' => $creatorTenant->id,
                'total_comments' => $total,
                'auto_approved' => (int) ($total * 0.50),
                'auto_actioned' => (int) ($total * 0.30),
                'queued' => (int) ($total * 0.20),
                'engagement_responses_sent' => 0,
                'avg_toxicity_score' => fake()->numberBetween(25, 45),
                'sentiment_positive' => fake()->randomFloat(2, 0.45, 0.70),
                'calculated_at' => $article->published_at,
            ]);
        }

        $this->command->info('✓ DemoSeeder complete: 2 tenants, 10 users, 210 articles, ~5500 comments');
        $this->command->info('  Demo login: GET /api/demo/switch/{email}');
        $this->command->info('  Password for all users: ' . self::DEMO_PASSWORD);
    }

    // ─── Helper: create a single comment with time-based toxicity trend ───
    private function createComment(Tenant $tenant, Article $article, bool $isCreator = false): Comment
    {
        $monthIndex = Carbon::parse($article->published_at)->month - 1;

        // Improving trend: toxicity decreases over months
        if ($isCreator) {
            $toxicity = fake()->numberBetween(5, 70);
        } else {
            $maxToxicity = max(30, 85 - ($monthIndex * 8));
            $toxicity = fake()->numberBetween(5, $maxToxicity);
        }

        $isHighToxicity = $toxicity > 70;
        $status = $this->weightedStatus($isHighToxicity);

        return Comment::create([
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'platform' => $article->platform,
            'original_text' => fake()->realText(fake()->numberBetween(30, 280)),
            'normalised_text' => null,
            'commenter_platform_id' => 'user_' . fake()->numerify('######'),
            'commenter_display_name' => fake()->userName(),
            'toxicity_score' => $toxicity,
            'confidence_score' => fake()->numberBetween(70, 99),
            'status' => $status,
            'routing_decision' => match ($status) {
                'approved', 'restored' => 'approve',
                'hidden' => 'hide',
                'deleted', 'confirmed_deleted' => 'delete',
                'pending' => 'queue',
            },
            'flagged_reason' => $isHighToxicity ? fake()->randomElement(['toxicity', 'personal_attack', 'hate_speech', 'harassment']) : null,
            'is_personal_attack' => false,
            'target_entity' => null,
            'hidden_at' => $status === 'hidden' ? now() : null,
            'hidden_by_ai' => $status === 'hidden',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => Carbon::parse($article->published_at)->addHours(fake()->numberBetween(1, 720)),
        ]);
    }

    private function weightedStatus(bool $isHighToxicity): string
    {
        if ($isHighToxicity) {
            return fake()->randomElement(['hidden', 'hidden', 'hidden', 'deleted', 'confirmed_deleted', 'pending']);
        }

        return fake()->randomElement([
            'approved', 'approved', 'approved', 'approved', 'approved', 'approved',
            'pending',
            'hidden',
            'restored',
        ]);
    }

    private function entitiesForSection(string $section): array
    {
        return match ($section) {
            'Football' => fake()->randomElements(['Arsenal', 'Manchester United', 'Liverpool', 'Chelsea', 'Tottenham', 'Manchester City', 'England'], 2),
            'Cricket' => fake()->randomElements(['England Cricket', 'Joe Root', 'Ben Stokes', 'India', 'Australia', 'The Ashes'], 2),
            'Rugby' => fake()->randomElements(['England Rugby', 'Wales', 'Ireland', 'Six Nations', 'Premiership'], 2),
            'Tennis' => fake()->randomElements(['Wimbledon', 'Andy Murray', 'Emma Raducanu', 'Jack Draper', 'ATP', 'WTA'], 2),
            default => [],
        };
    }

    private function generateSportHeadlines(): array
    {
        return [
            // Football (50)
            'Premier League Title Race: City and Arsenal Go Head to Head',
            'Transfer Deadline Day: Record-Breaking Moves Confirmed',
            'England Women Qualify for World Cup with Dominant Display',
            'VAR Under Fire After Another Controversial Weekend',
            'Champions League Draw: English Clubs Face Tough Tests',
            'Rising Stars: Five Young Players to Watch This Season',
            'Manager Sacked After Six-Game Losing Streak',
            'Record Attendance at Women\'s Super League Match',
            'Injury Crisis Hits England Squad Ahead of Qualifiers',
            'Premier League Weekend Preview: Key Battles Ahead',
            'Football Finance: Fair Play Rules Under Scrutiny',
            'Youth Academy Graduate Scores Stunning Debut Goal',
            'Derby Day: Form Guide and Key Matchups',
            'International Break: England Squad Announcement',
            'Transfer Rumours: Who\'s Moving in January?',
            'Referee Standards Review Announced by FA',
            'Historic Promotion for League Two Underdogs',
            'Stadium Expansion Plans Approved for Top-Flight Club',
            'Football and Mental Health: Players Open Up',
            'Tactical Analysis: Why 3-5-2 Is Back in Fashion',
            'Grassroots Football Funding Boost Announced',
            'Match Report: Thrilling 4-3 in London Derby',
            'Goal of the Month: Stunning 30-Yard Volley',
            'Managerial Merry-Go-Round Continues',
            'Cup Final Preview: Underdogs vs Favourites',
            'Football Governance Bill Passes Second Reading',
            'Player of the Year Nominees Revealed',
            'Summer Tour Fixtures Announced',
            'Relegation Battle Heats Up: Who\'s Going Down?',
            'New Kit Launches Divide Fan Opinion',
            'Match-Fixing Scandal Rocks Lower Leagues',
            'Free Agents: Best Available This Summer',
            'Pre-Season Friendly Results Round-Up',
            'Tactical Breakdown: Pressing vs Possession',
            'Award-Winning Journalist Joins BBC Sport',
            'Community Shield Preview and Predictions',
            'League Cup Third Round Upsets',
            'Winter Break: Should England Adopt One?',
            'Penalty Shootout Drama Decides Quarter-Final',
            'Football Analytics: xG Explained Simply',
            'Expansion of Women\'s Football Investment',
            'Controversial Handball Rule Change Proposed',
            'Nostalgic Look Back: Greatest Premier League Seasons',
            'New Manager Bounce: Early Signs of Progress',
            'Supporters\' Trust Secures Club Ownership',
            'BBC Sport Pundits Predict the Weekend Results',
            'Injury Update: Star Striker Faces Months Out',
            'European Super League Talks Resurface',
            'Football Shirt Friday Raises Record Amount',
            'End of Season Awards: Full List of Winners',

            // Cricket (50)
            'England Cricket: Summer Schedule Revealed',
            'The Ashes 2026: Can England Reclaim the Urn?',
            'Root Passes 12,000 Test Runs Milestone',
            'T20 World Cup Preview: England\'s Chances Assessed',
            'Women\'s Cricket: Record TV Audience for Hundred',
            'County Championship Round-Up: Week 10',
            'Fast Bowling Crisis: Where Are England\'s Quicks?',
            'IPL Auction: English Players Command Big Fees',
            'Cricket Scotland Investigation: Key Findings',
            'Bazball 2.0: Evolution of England\'s Approach',
            'Stokes Return Boosts England Test Hopes',
            'ECB Financial Review: State of the Game',
            'Cricket and Climate: Rain-Affected Seasons Rise',
            'Spin Bowling Masterclass: What Makes a Great Turner',
            'The Hundred Draft: Surprise Picks and Snubs',
            'England Lions Tour: Stars of the Future',
            'Cricket Discipline Panel Hands Down Bans',
            'County T20 Finals Day Preview',
            'Women\'s Ashes: England Close the Gap',
            'Cricket Technology: DRS Accuracy Report',
            'Player Workload Management Under Spotlight',
            'Historic First: Afghanistan Beat England in Tests',
            'Cricket World Cup 2027 Qualification Update',
            'Pitch Controversy at Lord\'s Test',
            'Young Talent: Best Under-21 Cricketers in England',
            'Cricket Broadcasting Rights: New Deal Details',
            'Rain-Affected Draw Frustrates Both Sides',
            'Record Individual Score in County Championship',
            'All-Rounder Rankings: Where Does Stokes Sit?',
            'Cricket Coaching Revolution: Data-Driven Methods',
            'ICC Test Championship Final Preview',
            'Retirement Announcement: Legend Hangs Up Bat',
            'Cricket in Schools: New ECB Initiative',
            'Oval Redevelopment Plans Unveiled',
            'England Squad Rotation Policy Debated',
            'Cricket Podcast: This Week\'s Talking Points',
            'Day-Night Test: Pros and Cons Revisited',
            'Domestic Cricket: Financial Health Check',
            'Cricket Photography Exhibition Opens in London',
            'Match Report: England Win Thriller by 2 Runs',
            'Overseas Player Quota: Should It Change?',
            'Cricket Stats Corner: Most Centuries This Year',
            'Groundsman of the Year: Behind the Scenes',
            'Charity Match Raises £500,000',
            'Cricket Memorabilia Auction: Record Prices',
            'End of Summer Review: Hits and Misses',
            'Winter Tour Squads Named',
            'Cricket Film Documentary Premieres',
            'Club Cricket: Grassroots Heroes Celebrated',
            'Annual Cricket Awards: Full Winners List',

            // Rugby (50)
            'Six Nations 2026: England Aiming for Grand Slam',
            'Premiership Round-Up: Weekend Results and Standings',
            'England Women\'s Rugby Reaches New Heights',
            'Lions Tour Announcement Sparks Excitement',
            'Concussion Protocols: New Guidelines Released',
            'World Cup Qualifying: Pacific Islands Impress',
            'Rugby Union vs League: The Crossover Players',
            'Bath Rugby\'s Remarkable Season Continues',
            'Injury Epidemic Raises Player Safety Questions',
            'Autumn Internationals Squad Selection Debated',
            'Referee Mic\'d Up: Insights from Match Officials',
            'Grassroots Rugby: Participation Numbers Rise',
            'Tactical Analysis: The Importance of the Breakdown',
            'Women\'s Premiership Attracts Record Investment',
            'Rugby Award Winners Announced',
            'Junior World Championship: England Triumph',
            'Championship Clubs Fight for Premiership Spot',
            'Rugby Financial Fair Play Rules Proposed',
            'Player Welfare Report: Progress and Challenges',
            'Seven-A-Side Stars Shine at International Event',
            'Historic Win for Japan Over England',
            'Rugby World Cup 2027 Host City Updates',
            'Former England Captain Returns to Coaching',
            'Match Report: Harlequins Edge Bristol in Thriller',
            'Rugby Technology: TMO Review Accuracy Stats',
            'Scrum Analysis: Why Set Piece Dominance Matters',
            'Youth Pathway: From Academy to International',
            'Championship Final Preview',
            'Rugby Charity Gala Raises Significant Funds',
            'Coaching Masterclass: Defence Wins Trophies',
            'International Rugby Calendar Restructure Proposed',
            'England\'s Midfield Partnership Debate',
            'Rugby League Challenge Cup Results',
            'Stadium Experience: Fan Satisfaction Survey Results',
            'Fly-Half Comparison: Who Should Start?',
            'Women\'s Six Nations Wrap-Up',
            'Rugby Podcast: Season So Far',
            'Pre-Season Training Insights',
            'Club vs Country Row Intensifies',
            'Rugby World Cup Warm-Up Fixtures Confirmed',
            'Historic Moment: First Professional Women\'s League',
            'Test Series Preview: England vs South Africa',
            'Rugby Analytics: What the Numbers Tell Us',
            'Retirement: Legendary Flanker Calls Time',
            'Community Rugby: Inspiring Stories',
            'Match-Day Experience Improvements Announced',
            'Barbarians Fixture Attracts Star Names',
            'End of Season Review: Premiership Highlights',
            'Summer Tour Results and Analysis',
            'Rugby Innovation: New Training Technologies',

            // Tennis (50)
            'Wimbledon 2026: Draw, Seeds, and Predictions',
            'British Tennis: Draper Breaks Into Top 10',
            'Raducanu Returns to Form at French Open',
            'Davis Cup: Britain\'s Group Stage Opponents Drawn',
            'Grass Court Season Preview: Who to Watch',
            'Tennis Integrity: Match-Fixing Cases Rise',
            'Andy Murray: Life After Professional Tennis',
            'Junior Talent: British 16-Year-Old Stuns at Slams',
            'WTA Season Review: Dominant Performances',
            'Doubles Tennis: Underappreciated Art Form',
            'ATP Race to Turin: Current Standings Analysed',
            'Tennis Technology: Hawk-Eye\'s Next Evolution',
            'Coaching Carousel: Top Players Switch Teams',
            'Queen\'s Club Tournament Preview',
            'Equal Prize Money Debate Continues',
            'Tennis Fitness: Modern Training Methods Revealed',
            'Grand Slam Records: Who Holds What',
            'British Tennis Investment Plan Announced',
            'Match Report: Five-Set Epic at Australian Open',
            'Tennis Parenting: Nurturing the Next Generation',
            'Indoor Season Results Round-Up',
            'Tennis Fashion: On-Court Style Evolution',
            'Federer Effect: How One Player Changed Tennis',
            'LTA Community Tennis Programme Expands',
            'Wheelchair Tennis: British Success Story',
            'New Tennis Centre Opens in Manchester',
            'Serve Speed Records: Who Hits Hardest?',
            'Clay Court Masters Preview',
            'Tennis Betting: Regulation Update',
            'Mental Health in Tennis: Players Speak Out',
            'Swiatek Dominance: Can Anyone Challenge Her?',
            'British Tennis Hall of Fame Inductees',
            'Tennis Calendar Reform Talks Continue',
            'Night Session Drama at Roland Garros',
            'Veterans Circuit: Tennis Never Gets Old',
            'Coaching Licence Requirements Updated',
            'US Open Build-Up: British Hopefuls',
            'Tennis Statistics: Who Won the Rally Count?',
            'Mixed Doubles: Olympic Medal Hopes',
            'Tennis Court Surfaces: The Science Behind Them',
            'Rising Talent Secures Wild Card Entry',
            'Post-Match Interview Goes Viral',
            'Tennis Podcast: Grand Slam Preview Special',
            'Retirement Tribute: Career Highlights Compilation',
            'British Tennis Awards Night',
            'Tennis and Social Media: Player Interaction',
            'Year-End Championships: Race Heats Up',
            'Tennis Coaching Technology: AI Analysis Tools',
            'End of Year Rankings: Movers and Shakers',
            'Tennis Season Preview: What to Expect in 2027',
        ];
    }
}
