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

class ClassifyCommentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 90];

    public function __construct(public Comment $comment)
    {
    }

    public function handle(): void
    {
        $comment = $this->comment;

        $article = $comment->article;

        $response = Http::timeout(15)
            ->post(config('services.tidemark_ai.url') . '/classify', [
                'comment_text' => $comment->original_text,
                'article_context' => $article?->title,
                'protected_entities' => $article?->subject_entities ?? [],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("tidemark-ai /classify returned {$response->status()}");
        }

        $data = $response->json();

        $comment->update([
            'normalised_text' => $data['normalised_text'] ?? $comment->original_text,
            'toxicity_score' => $data['toxicity_score'] ?? null,
            'confidence_score' => $data['confidence_score'] ?? null,
            'routing_decision' => $data['routing_decision'] ?? 'queue',
            'flagged_reason' => $data['flagged_reason'] ?? null,
            'is_personal_attack' => $data['is_personal_attack'] ?? false,
            'target_entity' => $data['target_entity'] ?? null,
        ]);

        RouteCommentJob::dispatch($comment)->onQueue('comments-high');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ClassifyCommentJob failed permanently', [
            'comment_id' => $this->comment->id,
            'error' => $exception->getMessage(),
        ]);

        // Leave as pending for manual review
        $this->comment->update(['status' => 'pending']);
    }
}
