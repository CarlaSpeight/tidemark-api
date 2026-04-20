<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Comment;
use App\Models\CommunityHighlight;
use App\Models\EngagementResponse;
use App\Models\SurgeEvent;
use App\Models\User;
use App\Traits\Auditable;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportingService
{
    use Auditable;

    private const ROOT_CAUSE_FLAGS = [
        'comparative_framing',
        'clickbait_headline',
        'sensitive_topic',
        'breaking_news_spike',
        'scheduling_pattern',
        'repeat_offender_audience',
    ];

    private const SUCCESS_FACTORS = [
        'achievement_framing',
        'neutral_headline',
        'analytical_angle',
        'strong_engagement_response',
        'local_interest',
        'positive_outcome',
    ];

    // ── Journalist Dashboard ────────────────────────────────

    public function journalistDashboard(User $user): array
    {
        $today = now()->startOfDay();

        $commentsQuery = Comment::query()->forUser($user);

        $stats = [
            'comments_today' => (clone $commentsQuery)->where('created_at', '>=', $today)->count(),
            'auto_actioned_today' => (clone $commentsQuery)
                ->where('created_at', '>=', $today)
                ->whereIn('status', ['approved', 'hidden', 'deleted'])
                ->whereNull('reviewed_by')
                ->count(),
            'queue_count' => (clone $commentsQuery)->where('status', 'pending')->count(),
            'tidemark_score' => $this->tidemarkScore($user),
        ];

        $trend = $this->thirtyDayTrend($user);

        $articlesToday = Article::query()
            ->where('journalist_id', $user->id)
            ->where('published_at', '>=', $today)
            ->with(['journalist', 'stat'])
            ->get();

        $engagementQuery = EngagementResponse::query()
            ->whereHas('article', fn ($q) => $q->where('journalist_id', $user->id));

        $engagementSummary = [
            'drafts_today' => (clone $engagementQuery)->where('status', 'draft')->where('created_at', '>=', $today)->count(),
            'awaiting_approval' => (clone $engagementQuery)->where('status', 'pending_approval')->count(),
            'auto_posted_today' => (clone $engagementQuery)->whereNotNull('posted_at')->where('posted_at', '>=', $today)->where('drafted_by_ai', true)->count(),
        ];

        return [
            'stats' => $stats,
            'trend' => $trend,
            'articles_today' => $articlesToday,
            'engagement_summary' => $engagementSummary,
        ];
    }

    // ── Editor Dashboard ────────────────────────────────────

    public function editorDashboard(User $user): array
    {
        $section = $user->section;

        $commentsQuery = Comment::query()->forUser($user);

        $totalComments = (clone $commentsQuery)->count();
        $autoHandled = (clone $commentsQuery)
            ->whereIn('status', ['approved', 'hidden', 'deleted'])
            ->whereNull('reviewed_by')
            ->count();

        $engagementQuery = EngagementResponse::query()
            ->whereHas('article.journalist', fn ($q) => $q->where('section', $section));

        $totalEngagement = (clone $engagementQuery)->count();
        $postedEngagement = (clone $engagementQuery)->whereNotNull('posted_at')->count();

        $sectionStats = [
            'avg_toxicity' => (int) round((clone $commentsQuery)->avg('toxicity_score') ?? 0),
            'total_comments' => $totalComments,
            'auto_handle_rate' => $totalComments > 0 ? round(($autoHandled / $totalComments) * 100, 1) : 0.0,
            'engagement_rate' => $totalComments > 0 ? round(($postedEngagement / $totalComments) * 100, 1) : 0.0,
        ];

        $bestPosts = $this->bestPosts($user, 10);
        $worstPosts = $this->worstPosts($user, 10);

        $team = $this->teamStats($section, $user->tenant_id);

        $topicHeatmap = StatsCache::topicHeatmap($user->tenant_id, fn () => $this->topicHeatmap($user));

        $engagementPerformance = $this->engagementPerformance($engagementQuery);

        return [
            'section_stats' => $sectionStats,
            'best_posts' => $bestPosts,
            'worst_posts' => $worstPosts,
            'team' => $team,
            'topic_heatmap' => $topicHeatmap,
            'engagement_performance' => $engagementPerformance,
        ];
    }

    // ── Senior Dashboard ────────────────────────────────────

    public function seniorDashboard(User $user): array
    {
        $commentsQuery = Comment::query()->forUser($user);
        $today = now()->startOfDay();
        $lastMonth = now()->subMonth();
        $thisMonth = now()->startOfMonth();

        $currentAvg = (clone $commentsQuery)->where('created_at', '>=', $thisMonth)->avg('toxicity_score') ?? 0;
        $lastMonthAvg = (clone $commentsQuery)->whereBetween('created_at', [$lastMonth->copy()->startOfMonth(), $lastMonth->copy()->endOfMonth()])->avg('toxicity_score') ?? 0;
        $trendVsLastMonth = $lastMonthAvg > 0 ? round((($currentAvg - $lastMonthAvg) / $lastMonthAvg) * 100, 1) : 0.0;

        $totalComments = (clone $commentsQuery)->count();
        $autoHandled = (clone $commentsQuery)->whereIn('status', ['approved', 'hidden', 'deleted'])->whereNull('reviewed_by')->count();

        $engagementQuery = EngagementResponse::query();
        $totalEngagement = (clone $engagementQuery)->count();
        $postedEngagement = (clone $engagementQuery)->whereNotNull('posted_at')->count();

        $welfareFlagsCount = User::where('tenant_id', $user->tenant_id)
            ->whereIn('role', ['journalist', 'producer', 'senior_reporter'])
            ->where('is_active', true)
            ->get()
            ->filter(fn ($j) => Comment::query()
                ->whereHas('article', fn ($q) => $q->where('journalist_id', $j->id))
                ->where('is_personal_attack', true)
                ->where('created_at', '>=', $thisMonth)
                ->count() > 50
            )->count();

        $publicationHealth = [
            'overall_score' => 100 - (int) round($currentAvg),
            'trend_vs_last_month' => $trendVsLastMonth,
            'hours_saved' => (int) round($autoHandled * 0.5 / 60, 0),
            'welfare_flags_count' => $welfareFlagsCount,
            'engagement_rate' => $totalComments > 0 ? round(($postedEngagement / $totalComments) * 100, 1) : 0.0,
        ];

        $sectionComparison = $this->sectionComparison($user);

        $bestPostsAll = $this->bestPosts($user, 10, true);
        $worstPostsAll = $this->worstPosts($user, 10, true);

        $welfareFlags = $this->welfareFlags($user);

        $crossSectionRisks = $this->crossSectionRisks($user);

        $engagementLeaderboard = $this->engagementLeaderboard($user);

        return [
            'publication_health' => $publicationHealth,
            'section_comparison' => $sectionComparison,
            'best_posts_all' => $bestPostsAll,
            'worst_posts_all' => $worstPostsAll,
            'welfare_flags' => $welfareFlags,
            'cross_section_risks' => $crossSectionRisks,
            'engagement_leaderboard' => $engagementLeaderboard,
        ];
    }

    // ── Creator Dashboard ───────────────────────────────────

    public function creatorDashboard(User $user): array
    {
        $commentsQuery = Comment::query()->forUser($user);
        $thisWeek = now()->startOfWeek();

        $total = (clone $commentsQuery)->count();
        $positive = (clone $commentsQuery)->where('toxicity_score', '<', 30)->count();
        $positivePct = $total > 0 ? (int) round(($positive / $total) * 100) : 100;

        $topThemes = (clone $commentsQuery)
            ->where('toxicity_score', '<', 30)
            ->whereNotNull('flagged_reason')
            ->select('flagged_reason', DB::raw('count(*) as cnt'))
            ->groupBy('flagged_reason')
            ->orderByDesc('cnt')
            ->limit(3)
            ->pluck('flagged_reason')
            ->toArray();

        $positiveSummary = [
            'positive_pct' => $positivePct,
            'top_positive_themes' => $topThemes,
        ];

        $handledCount = (clone $commentsQuery)
            ->whereIn('status', ['hidden', 'deleted', 'confirmed_deleted'])
            ->count();

        $handledSummary = [
            'handled_count' => $handledCount,
        ];

        $engagementQuery = EngagementResponse::query()
            ->whereHas('article.journalist', fn ($q) => $q->where('id', $user->id));

        $engagementSummary = [
            'responses_sent_this_week' => (clone $engagementQuery)->whereNotNull('posted_at')->where('posted_at', '>=', $thisWeek)->count(),
            'community_responses' => (clone $engagementQuery)->whereNotNull('posted_at')->count(),
        ];

        $communityHighlights = CommunityHighlight::query()
            ->where('creator_id', $user->id)
            ->with(['comment.article'])
            ->latest()
            ->limit(3)
            ->get();

        $surgeStatus = SurgeEvent::query()
            ->where('creator_id', $user->id)
            ->whereNull('resolved_at')
            ->latest('detected_at')
            ->first();

        $weeklyDigest = null; // WellbeingDigest model not yet created

        return [
            'positive_summary' => $positiveSummary,
            'handled_summary' => $handledSummary,
            'engagement_summary' => $engagementSummary,
            'community_highlights' => $communityHighlights,
            'surge_status' => $surgeStatus,
            'weekly_digest' => $weeklyDigest,
        ];
    }

    // ── Monthly Export ───────────────────────────────────────

    public function monthlyExport(User $user): string
    {
        $data = $this->seniorDashboard($user);
        $tenant = $user->tenant;

        $html = view('reports.monthly-export', [
            'data' => $data,
            'tenant' => $tenant,
            'generated_at' => now()->toDateTimeString(),
            'month' => now()->format('F Y'),
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        $year = now()->format('Y');
        $month = now()->format('m');
        $path = "reports/{$tenant->slug}/{$year}-{$month}.pdf";

        Storage::disk('s3')->put($path, $pdf->output());

        static::audit(
            'monthly_report_exported',
            'report',
            null,
            "Monthly report generated for {$tenant->name} ({$year}-{$month})",
        );

        return Storage::disk('s3')->temporaryUrl($path, now()->addHour());
    }

    // ── Private helpers ─────────────────────────────────────

    private function tidemarkScore(User $user): int
    {
        $query = Comment::query()->forUser($user);
        $total = (clone $query)->count();

        if ($total === 0) {
            return 100;
        }

        $approved = (clone $query)->where('status', 'approved')->count();
        $restored = (clone $query)->where('status', 'restored')->count();

        return min(100, (int) round((($approved + $restored) / $total) * 100));
    }

    private function thirtyDayTrend(User $user): array
    {
        $from = now()->subDays(30)->startOfDay();

        return Comment::query()
            ->forUser($user)
            ->where('created_at', '>=', $from)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('ROUND(AVG(toxicity_score)) as avg_toxicity'),
                DB::raw('COUNT(CASE WHEN status IN (\'hidden\',\'deleted\',\'confirmed_deleted\',\'approved\') THEN 1 END) as actioned_count'),
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'avg_toxicity' => (int) ($row->avg_toxicity ?? 0),
                'actioned_count' => (int) $row->actioned_count,
            ])
            ->toArray();
    }

    private function bestPosts(User $user, int $limit, bool $includeSection = false): array
    {
        $query = Article::query();
        $this->scopeArticlesForUser($query, $user);
        $query->whereHas('stat')
            ->with(['stat', 'journalist'])
            ->join('article_stats', 'articles.id', '=', 'article_stats.article_id')
            ->orderBy('article_stats.avg_toxicity_score', 'asc')
            ->orderBy('article_stats.sentiment_positive', 'desc')
            ->select('articles.*')
            ->limit($limit);

        return $query->get()->map(function ($article) use ($includeSection) {
            $stat = $article->stat;
            $result = [
                'id' => $article->id,
                'title' => $article->title,
                'url' => $article->url,
                'avg_toxicity' => (int) round($stat->avg_toxicity_score ?? 0),
                'total_comments' => $stat->total_comments ?? 0,
                'sentiment_positive' => round($stat->sentiment_positive ?? 0, 1),
                'success_factor' => $this->inferSuccessFactor($article),
            ];

            if ($includeSection) {
                $result['section'] = $article->journalist?->section;
            }

            return $result;
        })->toArray();
    }

    private function worstPosts(User $user, int $limit, bool $includeSection = false): array
    {
        $query = Article::query();
        $this->scopeArticlesForUser($query, $user);
        $query->whereHas('stat')
            ->with(['stat', 'journalist'])
            ->join('article_stats', 'articles.id', '=', 'article_stats.article_id')
            ->orderBy('article_stats.avg_toxicity_score', 'desc')
            ->select('articles.*')
            ->limit($limit);

        return $query->get()->map(function ($article) use ($includeSection) {
            $stat = $article->stat;
            $rootCause = $this->inferRootCause($article);
            $result = [
                'id' => $article->id,
                'title' => $article->title,
                'url' => $article->url,
                'avg_toxicity' => (int) round($stat->avg_toxicity_score ?? 0),
                'total_comments' => $stat->total_comments ?? 0,
                'root_cause_flag' => $rootCause,
                'suggestion_offered' => $rootCause !== null,
                'suggestion_accepted' => false,
            ];

            if ($includeSection) {
                $result['section'] = $article->journalist?->section;
            }

            return $result;
        })->toArray();
    }

    private function teamStats(string $section, string $tenantId): array
    {
        $journalists = User::where('tenant_id', $tenantId)
            ->where('section', $section)
            ->whereIn('role', ['journalist', 'producer', 'senior_reporter'])
            ->where('is_active', true)
            ->get();

        return $journalists->map(function ($journalist) {
            $comments = Comment::query()
                ->whereHas('article', fn ($q) => $q->where('journalist_id', $journalist->id));

            $avgTox = (int) round((clone $comments)->avg('toxicity_score') ?? 0);

            // 30-day sparkline
            $sparkline = (clone $comments)
                ->where('created_at', '>=', now()->subDays(30))
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('ROUND(AVG(toxicity_score)) as avg_toxicity'),
                )
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->pluck('avg_toxicity', 'date')
                ->toArray();

            // Engagement approved %
            $totalEngagement = EngagementResponse::whereHas('article', fn ($q) => $q->where('journalist_id', $journalist->id))->count();
            $approvedEngagement = EngagementResponse::whereHas('article', fn ($q) => $q->where('journalist_id', $journalist->id))
                ->whereNotNull('approved_at')
                ->count();
            $engagementApprovedPct = $totalEngagement > 0 ? round(($approvedEngagement / $totalEngagement) * 100, 1) : 0.0;

            // Status: compare last 7 days avg vs prior 23 days avg
            $recent = (clone $comments)->where('created_at', '>=', now()->subDays(7))->avg('toxicity_score') ?? 0;
            $prior = (clone $comments)->whereBetween('created_at', [now()->subDays(30), now()->subDays(7)])->avg('toxicity_score') ?? 0;
            $status = match (true) {
                $prior == 0 => 'Stable',
                $recent < $prior - 5 => 'Improving',
                $recent > $prior + 5 => 'Needs Support',
                default => 'Stable',
            };

            return [
                'id' => $journalist->id,
                'name' => $journalist->name,
                'avg_toxicity' => $avgTox,
                'sparkline' => $sparkline,
                'engagement_approved_pct' => $engagementApprovedPct,
                'status' => $status,
            ];
        })->toArray();
    }

    public function topicHeatmap(User $user): array
    {
        $from = now()->subWeeks(8)->startOfWeek();

        $query = Article::query();
        $this->scopeArticlesForUser($query, $user);
        $rows = $query->whereNotNull('topic_category')
            ->where('published_at', '>=', $from)
            ->with(['comments' => fn ($q) => $q->whereNotNull('toxicity_score')])
            ->get()
            ->groupBy('topic_category');

        $heatmap = [];

        foreach ($rows as $topic => $articles) {
            $weeks = [];
            for ($w = 0; $w < 8; $w++) {
                $weekStart = now()->subWeeks(7 - $w)->startOfWeek();
                $weekEnd = $weekStart->copy()->endOfWeek();

                $weekArticles = $articles->filter(fn ($a) => $a->published_at->between($weekStart, $weekEnd));
                $weekComments = $weekArticles->flatMap->comments;

                $weeks[] = $weekComments->isNotEmpty()
                    ? (int) round($weekComments->avg('toxicity_score'))
                    : null;
            }

            $heatmap[] = [
                'topic' => $topic,
                'weeks' => $weeks,
            ];
        }

        return $heatmap;
    }

    private function engagementPerformance($engagementQuery): array
    {
        $total = (clone $engagementQuery)->count();
        $responded = (clone $engagementQuery)->whereNotNull('posted_at')->count();
        $responseRate = $total > 0 ? round(($responded / $total) * 100, 1) : 0.0;

        $driver = DB::getDriverName();
        $avgResponseTimeMin = match ($driver) {
            'sqlite' => (clone $engagementQuery)
                ->whereNotNull('posted_at')
                ->selectRaw('AVG((julianday(posted_at) - julianday(created_at)) * 1440) as avg_min')
                ->value('avg_min'),
            default => (clone $engagementQuery)
                ->whereNotNull('posted_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (posted_at - created_at)) / 60) as avg_min')
                ->value('avg_min'),
        };

        return [
            'response_rate' => $responseRate,
            'avg_response_time_min' => (int) round($avgResponseTimeMin ?? 0),
            'positive_sentiment_after_pct' => 0.0, // requires sentiment analysis post-response
            'thread_depth_increase' => 0.0, // requires thread tracking
        ];
    }

    private function sectionComparison(User $user): array
    {
        $sections = User::where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->whereNotNull('section')
            ->distinct()
            ->pluck('section');

        return $sections->map(function ($section) use ($user) {
            $journalistIds = User::where('tenant_id', $user->tenant_id)
                ->where('section', $section)
                ->pluck('id');

            $comments = Comment::query()
                ->whereHas('article', fn ($q) => $q->whereIn('journalist_id', $journalistIds));

            $avgTox = (int) round((clone $comments)->avg('toxicity_score') ?? 0);

            $recentAvg = (clone $comments)->where('created_at', '>=', now()->subDays(7))->avg('toxicity_score') ?? 0;
            $priorAvg = (clone $comments)->whereBetween('created_at', [now()->subDays(30), now()->subDays(7)])->avg('toxicity_score') ?? 0;

            $trend = match (true) {
                $priorAvg == 0 => 'stable',
                $recentAvg < $priorAvg - 5 => 'improving',
                $recentAvg > $priorAvg + 5 => 'worsening',
                default => 'stable',
            };

            $totalComments = (clone $comments)->count();
            $engagementPosted = EngagementResponse::query()
                ->whereHas('article', fn ($q) => $q->whereIn('journalist_id', $journalistIds))
                ->whereNotNull('posted_at')
                ->count();

            return [
                'section' => $section,
                'avg_toxicity' => $avgTox,
                'trend' => $trend,
                'engagement_rate' => $totalComments > 0 ? round(($engagementPosted / $totalComments) * 100, 1) : 0.0,
            ];
        })->toArray();
    }

    private function welfareFlags(User $user): array
    {
        $thisMonth = now()->startOfMonth();

        return User::where('tenant_id', $user->tenant_id)
            ->whereIn('role', ['journalist', 'producer', 'senior_reporter'])
            ->where('is_active', true)
            ->get()
            ->filter(function ($journalist) use ($thisMonth) {
                return Comment::query()
                    ->whereHas('article', fn ($q) => $q->where('journalist_id', $journalist->id))
                    ->where('is_personal_attack', true)
                    ->where('created_at', '>=', $thisMonth)
                    ->count() > 50;
            })
            ->map(fn ($j) => [
                'journalist_id' => $j->id,
                'name' => $j->name,
                'section' => $j->section,
                'personal_attacks_this_month' => Comment::query()
                    ->whereHas('article', fn ($q) => $q->where('journalist_id', $j->id))
                    ->where('is_personal_attack', true)
                    ->where('created_at', '>=', $thisMonth)
                    ->count(),
            ])
            ->values()
            ->toArray();
    }

    private function crossSectionRisks(User $user): array
    {
        $thisMonth = now()->startOfMonth();

        $sections = User::where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->whereNotNull('section')
            ->distinct()
            ->pluck('section');

        // Find topics and which sections they are toxic in
        $topicSections = [];

        foreach ($sections as $section) {
            $journalistIds = User::where('tenant_id', $user->tenant_id)
                ->where('section', $section)
                ->pluck('id');

            $toxicTopics = Article::query()
                ->whereIn('journalist_id', $journalistIds)
                ->whereNotNull('topic_category')
                ->whereHas('comments', fn ($q) => $q->where('created_at', '>=', $thisMonth)->where('toxicity_score', '>=', 70))
                ->distinct()
                ->pluck('topic_category');

            foreach ($toxicTopics as $topic) {
                $topicSections[$topic] ??= [];
                $topicSections[$topic][] = $section;
            }
        }

        return collect($topicSections)
            ->filter(fn ($s) => count($s) >= 3)
            ->map(fn ($s, $topic) => [
                'topic' => $topic,
                'sections' => $s,
                'section_count' => count($s),
            ])
            ->values()
            ->toArray();
    }

    private function engagementLeaderboard(User $user): array
    {
        $sections = User::where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->whereNotNull('section')
            ->distinct()
            ->pluck('section');

        return $sections->map(function ($section) use ($user) {
            $journalistIds = User::where('tenant_id', $user->tenant_id)
                ->where('section', $section)
                ->pluck('id');

            $totalEngagement = EngagementResponse::query()
                ->whereHas('article', fn ($q) => $q->whereIn('journalist_id', $journalistIds))
                ->count();

            $postedEngagement = EngagementResponse::query()
                ->whereHas('article', fn ($q) => $q->whereIn('journalist_id', $journalistIds))
                ->whereNotNull('posted_at')
                ->count();

            $approvedEngagement = EngagementResponse::query()
                ->whereHas('article', fn ($q) => $q->whereIn('journalist_id', $journalistIds))
                ->whereNotNull('approved_at')
                ->count();

            $qualityScore = $totalEngagement > 0
                ? (int) round((($postedEngagement + $approvedEngagement) / ($totalEngagement * 2)) * 100)
                : 0;

            return [
                'section' => $section,
                'total_engagements' => $totalEngagement,
                'posted' => $postedEngagement,
                'quality_score' => $qualityScore,
            ];
        })
            ->sortByDesc('quality_score')
            ->values()
            ->toArray();
    }

    private function inferSuccessFactor(Article $article): string
    {
        $title = strtolower($article->title);
        $toxicity = $article->stat?->avg_toxicity_score ?? 50;
        $engagement = $article->stat?->engagement_responses_sent ?? 0;

        if ($engagement > 3) {
            return 'strong_engagement_response';
        }

        if (str_contains($title, 'local') || str_contains($title, 'community')) {
            return 'local_interest';
        }

        if (str_contains($title, 'win') || str_contains($title, 'achieve') || str_contains($title, 'success')) {
            return 'achievement_framing';
        }

        if (str_contains($title, 'analysis') || str_contains($title, 'study') || str_contains($title, 'research')) {
            return 'analytical_angle';
        }

        if ($toxicity < 20) {
            return 'positive_outcome';
        }

        return 'neutral_headline';
    }

    private function scopeArticlesForUser($query, User $user): void
    {
        match ($user->role) {
            'journalist', 'producer', 'senior_reporter', 'creator' => $query->where('journalist_id', $user->id),
            'deputy_editor', 'editor' => $query->whereHas('journalist', fn ($q) => $q->where('section', $user->section)),
            'agent' => $query->whereHas('journalist.creatorProfile', fn ($q) => $q->where('manager_user_id', $user->id)),
            default => $query,
        };
    }

    private function inferRootCause(Article $article): ?string
    {
        $title = strtolower($article->title);
        $toxicity = $article->stat?->avg_toxicity_score ?? 0;

        if ($toxicity < 40) {
            return null;
        }

        if (str_contains($title, 'vs') || str_contains($title, 'compared') || str_contains($title, 'better than')) {
            return 'comparative_framing';
        }

        if (str_contains($title, '!') || str_contains($title, 'shocking') || str_contains($title, 'you won\'t believe')) {
            return 'clickbait_headline';
        }

        if (in_array($article->sensitivity_level, ['high', 'critical'])) {
            return 'sensitive_topic';
        }

        if ($article->topic_category === 'breaking_news') {
            return 'breaking_news_spike';
        }

        return 'sensitive_topic';
    }
}
