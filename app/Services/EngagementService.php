<?php

namespace App\Services;

use App\Jobs\PostEngagementResponseJob;
use App\Jobs\TrainToneProfileJob;
use App\Models\Comment;
use App\Models\EngagementResponse;
use App\Models\ToneOfVoiceProfile;
use App\Models\User;
use App\Traits\Auditable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EngagementService
{
    use Auditable;

    // ── Queue ────────────────────────────────────────────────

    public function getQueue(User $user, Request $request): LengthAwarePaginator
    {
        $query = EngagementResponse::query()
            ->where('status', 'draft')
            ->with(['comment.article.journalist', 'article', 'approver', 'toneProfile']);

        $this->scopeByRole($query, $user);

        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }

        if ($request->filled('response_type')) {
            $query->where('response_type', $request->input('response_type'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        $query->orderByDesc('created_at');

        return $query->paginate(
            perPage: min((int) $request->input('per_page', 20), 100),
            page: (int) $request->input('page', 1),
        );
    }

    // ── Approve ──────────────────────────────────────────────

    public function approve(EngagementResponse $draft, User $user): void
    {
        $draft->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        PostEngagementResponseJob::dispatch($draft)->onQueue('comments-high');

        static::audit('engagement_approved', 'engagement_response', $draft->id, "Engagement draft approved");
    }

    // ── Edit and approve ─────────────────────────────────────

    public function editAndApprove(EngagementResponse $draft, string $editedText, User $user): void
    {
        $draft->update([
            'final_text' => $editedText,
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        PostEngagementResponseJob::dispatch($draft)->onQueue('comments-high');

        static::audit('engagement_edited_and_approved', 'engagement_response', $draft->id, 'Human edited AI draft before approval', [
            'original_draft' => $draft->draft_text,
            'edited_text' => $editedText,
        ]);
    }

    // ── Reject ───────────────────────────────────────────────

    public function reject(EngagementResponse $draft, User $user, ?string $reason = null): void
    {
        $draft->update([
            'status' => 'rejected',
        ]);

        static::audit('engagement_rejected', 'engagement_response', $draft->id, 'Engagement draft rejected', [
            'reason' => $reason,
            'draft_text' => $draft->draft_text,
        ]);
    }

    // ── Tone profiles ────────────────────────────────────────

    public function saveToneProfile(array $data, User $user): ToneOfVoiceProfile
    {
        $defaults = [
            'formality_level' => 'neutral',
            'personality_traits' => [],
            'topics_to_avoid' => [],
            'rival_brands_to_avoid' => [],
            'auto_response_types' => [],
        ];

        $profile = ToneOfVoiceProfile::withoutGlobalScope('tenant')->updateOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'name' => $data['name'],
            ],
            array_merge($defaults, $data, ['tenant_id' => $user->tenant_id]),
        );

        TrainToneProfileJob::dispatch($profile)->onQueue('comments-high');

        static::audit('tone_profile_saved', 'tone_of_voice_profile', $profile->id, "Tone profile '{$profile->name}' saved");

        return $profile;
    }

    // ── Performance ──────────────────────────────────────────

    public function getPerformance(User $user): array
    {
        $query = EngagementResponse::query();
        $this->scopeByRole($query, $user);

        $total = (clone $query)->count();
        $posted = (clone $query)->whereNotNull('posted_at')->count();
        $responseRate = $total > 0 ? round(($posted / $total) * 100, 1) : 0.0;

        // Avg response time (SQLite-compatible)
        $avgResponseTimeMin = (clone $query)
            ->whereNotNull('posted_at')
            ->selectRaw('AVG((julianday(posted_at) - julianday(created_at)) * 1440) as avg_min')
            ->value('avg_min');

        // By response type
        $byType = (clone $query)
            ->select('response_type', DB::raw('count(*) as total'), DB::raw('SUM(CASE WHEN posted_at IS NOT NULL THEN 1 ELSE 0 END) as posted'))
            ->groupBy('response_type')
            ->get()
            ->map(fn ($row) => [
                'response_type' => $row->response_type,
                'total' => $row->total,
                'posted' => (int) $row->posted,
                'rate' => $row->total > 0 ? round(($row->posted / $row->total) * 100, 1) : 0.0,
            ])
            ->toArray();

        // Previous period comparison (30 days)
        $now = now();
        $currentPeriodStart = $now->copy()->subDays(30);
        $previousPeriodStart = $now->copy()->subDays(60);

        $currentQuery = (clone $query)->where('created_at', '>=', $currentPeriodStart);
        $previousQuery = (clone $query)->whereBetween('created_at', [$previousPeriodStart, $currentPeriodStart]);

        $currentTotal = (clone $currentQuery)->count();
        $currentPosted = (clone $currentQuery)->whereNotNull('posted_at')->count();
        $previousTotal = (clone $previousQuery)->count();
        $previousPosted = (clone $previousQuery)->whereNotNull('posted_at')->count();

        $currentRate = $currentTotal > 0 ? round(($currentPosted / $currentTotal) * 100, 1) : 0.0;
        $previousRate = $previousTotal > 0 ? round(($previousPosted / $previousTotal) * 100, 1) : 0.0;

        return [
            'response_rate' => $responseRate,
            'avg_response_time_min' => (int) round($avgResponseTimeMin ?? 0),
            'positive_sentiment_after_pct' => 0.0, // requires post-response sentiment analysis
            'thread_depth_increase' => 0.0, // requires thread tracking
            'by_response_type' => $byType,
            'comparison' => [
                'current_period_rate' => $currentRate,
                'previous_period_rate' => $previousRate,
                'change' => round($currentRate - $previousRate, 1),
            ],
        ];
    }

    // ── Pause / Resume ───────────────────────────────────────

    public function pause(User $user): void
    {
        Cache::put("engagement:paused:{$user->tenant_id}", true);

        static::audit('engagement_paused', 'tenant', $user->tenant_id, 'Engagement auto-posting paused (kill switch activated)');
    }

    public function resume(User $user): void
    {
        Cache::forget("engagement:paused:{$user->tenant_id}");

        static::audit('engagement_resumed', 'tenant', $user->tenant_id, 'Engagement auto-posting resumed');
    }

    public function isPaused(string $tenantId): bool
    {
        return (bool) Cache::get("engagement:paused:{$tenantId}");
    }

    // ── Guardrails ───────────────────────────────────────────

    public function validateDraftEligibility(Comment $comment): ?string
    {
        if (in_array($comment->status, ['hidden', 'deleted', 'confirmed_deleted'])) {
            return 'Cannot draft a response to a hidden or deleted comment.';
        }

        if (($comment->toxicity_score ?? 100) > 30) {
            return 'Cannot draft a response to a comment with toxicity score above 30.';
        }

        return null;
    }

    public function validateResponseText(string $text): ?string
    {
        if (preg_match('/https?:\/\/\S+/i', $text)) {
            return 'Response text must not contain URLs.';
        }

        if (strip_tags($text) !== $text) {
            return 'Response text must not contain HTML.';
        }

        if (mb_strlen($text) > 500) {
            return 'Response text must not exceed 500 characters.';
        }

        return null;
    }

    // ── Private ──────────────────────────────────────────────

    private function scopeByRole($query, User $user): void
    {
        match ($user->role) {
            'journalist', 'producer', 'senior_reporter', 'creator' => $query->whereHas('article', fn ($q) => $q->where('journalist_id', $user->id)),
            'deputy_editor', 'editor' => $query->whereHas('article.journalist', fn ($q) => $q->where('section', $user->section)),
            'agent' => $query->whereHas('article.journalist.creatorProfile', fn ($q) => $q->where('manager_user_id', $user->id)),
            default => $query,
        };
    }
}
