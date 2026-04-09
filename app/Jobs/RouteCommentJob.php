<?php

namespace App\Jobs;

use App\Models\Comment;
use App\Models\CreatorProfile;
use App\Models\SocialConnection;
use App\Services\SocialMedia\PlatformServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RouteCommentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 45];

    public function __construct(public Comment $comment)
    {
    }

    public function handle(): void
    {
        $comment = $this->comment;
        $decision = $comment->routing_decision;

        if (! $decision) {
            return;
        }

        if ($comment->platform === 'substack') {
            return;
        }

        match ($comment->platform) {
            'instagram', 'tiktok' => $this->routeDeleteOnly($comment, $decision),
            'facebook', 'youtube' => $this->routeWithHide($comment, $decision),
            'website' => $this->routeWebsite($comment, $decision),
            default => $this->routeWithHide($comment, $decision),
        };

        // ── Creator-tier protection ──────────────────────────
        $comment->refresh();
        $this->applyCreatorProtection($comment);

        // After routing approved non-toxic comments, check engagement eligibility
        $comment->refresh();
        if ($comment->status === 'approved' && ($comment->toxicity_score ?? 100) < 30) {
            CheckEngagementEligibilityJob::dispatch($comment)->onQueue('comments-high');
        }
    }

    /**
     * Creator-tier protection: classify comments on creator-owned content
     * and enforce protection rules while preserving healthy debate.
     */
    private function applyCreatorProtection(Comment $comment): void
    {
        // Only act on comments that survived standard routing as approved/pending
        if (in_array($comment->status, ['deleted', 'hidden', 'confirmed_deleted'])) {
            return;
        }

        // Check if article belongs to a creator
        $article = $comment->article;
        if (! $article) {
            return;
        }

        $creatorProfile = CreatorProfile::withoutGlobalScope('tenant')
            ->where('user_id', $article->journalist_id)
            ->first();

        if (! $creatorProfile) {
            return;
        }

        $baseUrl = rtrim(config('services.tidemark_ai.url', 'http://localhost:8001'), '/');

        try {
            $response = Http::timeout(10)->post("{$baseUrl}/creator/classify", [
                'comment_text' => $comment->original_text,
                'creator_profile' => [
                    'vibe_shield_level' => $creatorProfile->vibe_shield_level,
                    'allow_body_comments' => $creatorProfile->allow_body_comments,
                    'allow_relationship_comments' => $creatorProfile->allow_relationship_comments,
                    'allow_success_shaming' => $creatorProfile->allow_success_shaming,
                    'custom_protection_rules' => $creatorProfile->custom_protection_rules ?? [],
                ],
            ]);

            if (! $response->successful()) {
                Log::warning('Creator classify request failed', [
                    'status' => $response->status(),
                    'comment_id' => $comment->id,
                ]);

                return;
            }

            $result = $response->json();
            $violatesRules = $result['violates_creator_rules'] ?? false;
            $isHealthyDebate = $result['is_healthy_debate'] ?? false;

            // Healthy debate exception: approve unless base toxicity > 85
            if ($isHealthyDebate) {
                if (($comment->toxicity_score ?? 0) > 85) {
                    $this->creatorModerate($comment);
                } else {
                    $comment->update(['status' => 'approved']);
                }

                return;
            }

            // Violates creator rules and NOT healthy debate → moderate
            if ($violatesRules) {
                $this->creatorModerate($comment);
            }
        } catch (\Exception $e) {
            Log::error('Creator protection classification error', [
                'error' => $e->getMessage(),
                'comment_id' => $comment->id,
            ]);
        }
    }

    /**
     * Apply platform-appropriate moderation for creator protection.
     * Instagram/TikTok: delete. Facebook/YouTube/Website: hide.
     */
    private function creatorModerate(Comment $comment): void
    {
        match ($comment->platform) {
            'instagram', 'tiktok' => $this->performDelete($comment),
            default => $this->performHide($comment),
        };
    }

    /**
     * Instagram and TikTok: delete or approve only. NEVER hide.
     */
    private function routeDeleteOnly(Comment $comment, string $decision): void
    {
        match ($decision) {
            'delete' => $this->performDelete($comment),
            'approve' => $comment->update(['status' => 'approved']),
            'queue' => $comment->update(['status' => 'pending']),
            // If AI says 'hide' for Instagram/TikTok, treat as queue for manual review
            'hide' => $comment->update(['status' => 'pending']),
        };
    }

    /**
     * Facebook and YouTube: hide, approve, or queue.
     */
    private function routeWithHide(Comment $comment, string $decision): void
    {
        match ($decision) {
            'hide' => $this->performHide($comment),
            'approve' => $comment->update(['status' => 'approved']),
            'queue' => $comment->update(['status' => 'pending']),
            'delete' => $this->performDelete($comment),
        };
    }

    /**
     * Website: set visible flag, no external API call.
     */
    private function routeWebsite(Comment $comment, string $decision): void
    {
        match ($decision) {
            'hide' => $comment->update([
                'status' => 'hidden',
                'hidden_by_ai' => true,
                'hidden_at' => now(),
            ]),
            'approve' => $comment->update(['status' => 'approved']),
            'queue' => $comment->update(['status' => 'pending']),
            'delete' => $comment->update(['status' => 'deleted']),
        };
    }

    private function performHide(Comment $comment): void
    {
        $connection = $this->getConnection($comment);

        if ($connection) {
            $service = PlatformServiceFactory::make($comment->platform);
            $service->hideComment($comment->commenter_platform_id, $connection);
        }

        $comment->update([
            'status' => 'hidden',
            'hidden_by_ai' => true,
            'hidden_at' => now(),
        ]);
    }

    private function performDelete(Comment $comment): void
    {
        $connection = $this->getConnection($comment);

        if ($connection) {
            $service = PlatformServiceFactory::make($comment->platform);
            $service->deleteComment($comment->commenter_platform_id, $connection);
        }

        $comment->update(['status' => 'deleted']);
    }

    private function getConnection(Comment $comment): ?SocialConnection
    {
        return SocialConnection::withoutGlobalScope('tenant')
            ->where('tenant_id', $comment->tenant_id)
            ->where('platform', $comment->platform)
            ->where('is_active', true)
            ->first();
    }
}
