<?php

namespace App\Services\SocialMedia;

use App\Jobs\ClassifyCommentJob;
use App\Models\Article;
use App\Models\Comment;
use App\Models\SocialConnection;
use App\Models\Tenant;
use App\Notifications\SocialTokenExpiringNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractPlatformService implements PlatformServiceInterface
{
    private const MAX_RETRIES = 4;

    abstract protected function fetchComments(SocialConnection $connection, string $since): array|false;

    abstract protected function resolveArticle(array $commentData, Tenant $tenant): ?Article;

    public function pollNewComments(SocialConnection $connection, Tenant $tenant): void
    {
        $since = $connection->last_polled_at?->toISOString() ?? now()->subHours(24)->toISOString();

        try {
            $comments = $this->fetchWithBackoff($connection, $since);
        } catch (TokenExpiredException $e) {
            $this->handleExpiredToken($connection);

            return;
        }

        foreach ($comments as $commentData) {
            $article = $this->resolveArticle($commentData, $tenant);

            if (! $article) {
                continue;
            }

            $exists = Comment::withoutGlobalScope('tenant')
                ->where('commenter_platform_id', $commentData['id'])
                ->where('article_id', $article->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $comment = Comment::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenant->id,
                'article_id' => $article->id,
                'platform' => $connection->platform,
                'original_text' => $commentData['text'],
                'commenter_platform_id' => $commentData['id'],
                'commenter_display_name' => $commentData['author'] ?? null,
                'status' => 'pending',
            ]);

            ClassifyCommentJob::dispatch($comment)->onQueue('comments-high');
        }

        $connection->update(['last_polled_at' => now()]);
    }

    protected function fetchWithBackoff(SocialConnection $connection, string $since): array
    {
        $delay = 1;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $comments = $this->fetchComments($connection, $since);

            if ($comments !== false) {
                return $comments;
            }

            if ($attempt < self::MAX_RETRIES) {
                usleep($delay * 1_000_000);
                $delay *= 2;
            }
        }

        Log::warning("Rate limited after retries", [
            'platform' => $connection->platform,
            'connection_id' => $connection->id,
        ]);

        return [];
    }

    protected function handleExpiredToken(SocialConnection $connection): void
    {
        $connection->update(['needs_reauth' => true]);

        Log::warning("Token expired — needs reauth", [
            'platform' => $connection->platform,
            'connection_id' => $connection->id,
        ]);

        $admin = $connection->tenant?->users()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->first();

        if ($admin) {
            $admin->notify(new SocialTokenExpiringNotification($connection));
        }
    }

    protected function makeRequest(string $method, string $url, SocialConnection $connection, array $options = []): \Illuminate\Http\Client\Response|false
    {
        $response = Http::withToken($connection->access_token)->{$method}($url, $options);

        if ($response->status() === 401) {
            throw new TokenExpiredException();
        }

        if ($response->status() === 429) {
            return false;
        }

        return $response;
    }
}
