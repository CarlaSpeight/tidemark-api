<?php

namespace App\Jobs;

use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckEngagementEligibilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public Comment $comment)
    {
    }

    public function handle(): void
    {
        $comment = $this->comment;

        $response = Http::timeout(10)
            ->post(config('services.tidemark_ai.url') . '/engagement/evaluate', [
                'comment_id' => $comment->id,
                'comment_text' => $comment->original_text,
                'platform' => $comment->platform,
                'article_title' => $comment->article?->title,
                'toxicity_score' => $comment->toxicity_score,
            ]);

        if ($response->failed()) {
            Log::warning('Engagement evaluation failed', [
                'comment_id' => $comment->id,
                'status' => $response->status(),
            ]);

            return;
        }

        $data = $response->json();

        if (! empty($data['should_respond'])) {
            DraftEngagementResponseJob::dispatch($comment)->onQueue('comments-high');
        }
    }
}
