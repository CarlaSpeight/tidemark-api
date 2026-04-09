<?php

namespace App\Jobs;

use App\Models\EngagementResponse;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostEngagementResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public EngagementResponse $engagementResponse)
    {
    }

    public function handle(): void
    {
        $response = $this->engagementResponse;

        // Guardrail: check pause flag
        if (Cache::get("engagement:paused:{$response->tenant_id}")) {
            // Requeue for 15 minutes
            self::dispatch($response)->onQueue('comments-high')->delay(now()->addMinutes(15));

            Log::info('PostEngagementResponseJob paused — requeued for 15 min', [
                'engagement_response_id' => $response->id,
                'tenant_id' => $response->tenant_id,
            ]);

            return;
        }

        // Guardrail: never post a response containing URLs
        $text = $response->final_text ?? $response->draft_text;
        if (preg_match('/https?:\/\/\S+/i', $text)) {
            Log::warning('PostEngagementResponseJob blocked — response contains URL', [
                'engagement_response_id' => $response->id,
            ]);
            $response->update(['status' => 'draft']);

            return;
        }

        $comment = $response->comment;

        if (! $comment) {
            Log::warning('PostEngagementResponseJob: comment not found', [
                'engagement_response_id' => $response->id,
            ]);

            return;
        }

        // Guardrail: never post to hidden/deleted comments
        if (in_array($comment->status, ['hidden', 'deleted', 'confirmed_deleted'])) {
            Log::info('PostEngagementResponseJob skipped — comment is hidden/deleted', [
                'engagement_response_id' => $response->id,
            ]);
            $response->update(['status' => 'rejected']);

            return;
        }

        if ($comment->platform === 'website') {
            // Website: just mark as posted (no external API)
            $response->update([
                'status' => 'posted',
                'posted_at' => now(),
            ]);

            return;
        }

        $connection = SocialConnection::withoutGlobalScope('tenant')
            ->where('tenant_id', $response->tenant_id)
            ->where('platform', $comment->platform)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            Log::warning('PostEngagementResponseJob: no active connection', [
                'engagement_response_id' => $response->id,
                'platform' => $comment->platform,
            ]);
            $response->update(['status' => 'draft']);

            return;
        }

        $apiResponse = $this->postToplatform($comment->platform, $text, $comment->commenter_platform_id, $connection);

        if ($apiResponse && $apiResponse->successful()) {
            $response->update([
                'status' => 'posted',
                'posted_at' => now(),
                'platform_response_id' => $apiResponse->json('id') ?? $apiResponse->json('data.id'),
            ]);
        } else {
            $response->update(['status' => 'draft']);

            // Notify moderator of failure
            Log::warning('PostEngagementResponseJob failed — reverted to draft', [
                'engagement_response_id' => $response->id,
                'platform' => $comment->platform,
                'status' => $apiResponse?->status(),
            ]);
        }
    }

    private function postToplatform(string $platform, string $text, string $commentId, SocialConnection $connection): ?\Illuminate\Http\Client\Response
    {
        return match ($platform) {
            'facebook' => Http::withToken($connection->access_token)
                ->post("https://graph.facebook.com/v19.0/{$commentId}/comments", [
                    'message' => $text,
                ]),
            'instagram' => Http::withToken($connection->access_token)
                ->post("https://graph.facebook.com/v19.0/{$commentId}/replies", [
                    'message' => $text,
                ]),
            'twitter' => Http::withToken($connection->access_token)
                ->post('https://api.twitter.com/2/tweets', [
                    'text' => $text,
                    'reply' => ['in_reply_to_tweet_id' => $commentId],
                ]),
            'youtube' => Http::withToken($connection->access_token)
                ->post('https://www.googleapis.com/youtube/v3/comments', [
                    'snippet' => [
                        'parentId' => $commentId,
                        'textOriginal' => $text,
                    ],
                ]),
            'tiktok' => Http::withToken($connection->access_token)
                ->post('https://open.tiktokapis.com/v2/comment/reply/', [
                    'comment_id' => $commentId,
                    'text' => $text,
                ]),
            'linkedin' => Http::withToken($connection->access_token)
                ->post("https://api.linkedin.com/v2/socialActions/{$commentId}/comments", [
                    'message' => ['text' => $text],
                ]),
            default => null,
        };
    }
}
