<?php

namespace App\Jobs;

use App\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RetroAnalysePostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 90];

    public function __construct(
        public Article $article,
        public string $batchId,
    ) {}

    public function handle(): void
    {
        $aiUrl = rtrim(config('services.tidemark_ai.url', 'http://localhost:8001'), '/');

        try {
            $response = Http::timeout(20)
                ->withHeaders(['X-Internal-Key' => config('services.tidemark_ai.key', '')])
                ->post("{$aiUrl}/post-history/score", [
                    'headline' => $this->article->title,
                    'caption'  => $this->article->caption,
                    'topic'    => $this->article->topic_category ?? 'general',
                    'post_id'  => (string) $this->article->id,
                ]);

            if ($response->successful()) {
                $result = $response->json();

                $this->article->update([
                    'predicted_score' => $result['predicted_score'] ?? null,
                    'risk_level'      => $result['risk_level'] ?? null,
                ]);
            } else {
                Log::warning('RetroAnalysePostJob: AI returned non-200', [
                    'article_id' => $this->article->id,
                    'status'     => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('RetroAnalysePostJob failed', [
                'article_id' => $this->article->id,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            // Always increment batch progress counter
            $batchKey = "prescore_batch:{$this->batchId}";
            $status   = Cache::get($batchKey);

            if ($status) {
                $status['completed']++;
                if ($status['completed'] >= $status['total']) {
                    $status['status'] = 'completed';
                }
                Cache::put($batchKey, $status, now()->addHours(2));
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RetroAnalysePostJob permanently failed', [
            'article_id' => $this->article->id,
            'error'      => $e->getMessage(),
        ]);
    }
}
