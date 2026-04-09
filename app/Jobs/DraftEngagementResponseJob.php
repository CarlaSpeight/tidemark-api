<?php

namespace App\Jobs;

use App\Models\Comment;
use App\Models\EngagementResponse;
use App\Models\ToneOfVoiceProfile;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class DraftEngagementResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [10, 30];

    public function __construct(public Comment $comment)
    {
    }

    public function handle(): void
    {
        $comment = $this->comment;

        // Guardrail: never draft for hidden/deleted comments
        if (in_array($comment->status, ['hidden', 'deleted', 'confirmed_deleted'])) {
            Log::info('DraftEngagementResponseJob skipped — comment status disqualifies', [
                'comment_id' => $comment->id,
                'status' => $comment->status,
            ]);

            return;
        }

        // Guardrail: never draft for toxic comments
        if (($comment->toxicity_score ?? 100) > 30) {
            Log::info('DraftEngagementResponseJob skipped — toxicity too high', [
                'comment_id' => $comment->id,
                'toxicity_score' => $comment->toxicity_score,
            ]);

            return;
        }

        // Skip if already has an engagement response
        if ($comment->engagementResponse()->exists()) {
            return;
        }

        $toneProfile = ToneOfVoiceProfile::withoutGlobalScope('tenant')
            ->where('tenant_id', $comment->tenant_id)
            ->where('is_active', true)
            ->first();

        $response = Http::timeout(15)
            ->post(config('services.tidemark_ai.url') . '/engagement/draft', [
                'comment_id' => $comment->id,
                'comment_text' => $comment->original_text,
                'platform' => $comment->platform,
                'article_title' => $comment->article?->title,
                'article_url' => $comment->article?->url,
                'subject_entities' => $comment->article?->subject_entities ?? [],
                'tone_profile' => $toneProfile ? [
                    'name' => $toneProfile->name,
                    'formality_level' => $toneProfile->formality_level,
                    'personality_traits' => $toneProfile->personality_traits,
                    'topics_to_avoid' => $toneProfile->topics_to_avoid,
                    'custom_instructions' => $toneProfile->custom_instructions,
                ] : null,
            ]);

        if ($response->failed()) {
            Log::warning('Engagement draft generation failed', [
                'comment_id' => $comment->id,
                'status' => $response->status(),
            ]);

            return;
        }

        $data = $response->json();
        $draftText = $data['draft_text'] ?? '';

        // Guardrail: strip URLs from draft
        $draftText = preg_replace('/https?:\/\/\S+/i', '', $draftText);
        $draftText = trim($draftText);

        if (empty($draftText)) {
            return;
        }

        $engagementResponse = EngagementResponse::withoutGlobalScope('tenant')->create([
            'tenant_id' => $comment->tenant_id,
            'comment_id' => $comment->id,
            'article_id' => $comment->article_id,
            'platform' => $comment->platform,
            'draft_text' => $draftText,
            'final_text' => $draftText,
            'response_type' => $data['response_type'] ?? 'acknowledgement',
            'tone_profile_id' => $toneProfile?->id,
            'status' => 'draft',
            'drafted_by_ai' => true,
        ]);

        // Auto-post if enabled and not paused
        if (
            $toneProfile
            && $toneProfile->auto_response_enabled
            && is_array($toneProfile->auto_response_types)
            && in_array($engagementResponse->response_type, $toneProfile->auto_response_types)
            && ! Cache::get("engagement:paused:{$comment->tenant_id}")
        ) {
            PostEngagementResponseJob::dispatch($engagementResponse)->onQueue('comments-high');

            return;
        }

        // Notify community manager that draft awaits approval
        $manager = User::where('tenant_id', $comment->tenant_id)
            ->where('is_active', true)
            ->whereIn('role', ['section_editor', 'senior_editor', 'admin'])
            ->first();

        if ($manager) {
            Log::info('Engagement draft awaiting approval', [
                'engagement_response_id' => $engagementResponse->id,
                'notified_user' => $manager->id,
            ]);
        }
    }
}
