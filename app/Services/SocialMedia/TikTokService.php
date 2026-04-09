<?php

namespace App\Services\SocialMedia;

use App\Models\Article;
use App\Models\SocialConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

class TikTokService extends AbstractPlatformService
{
    protected function fetchComments(SocialConnection $connection, string $since): array|false
    {
        $response = $this->makeRequest(
            'post',
            'https://open.tiktokapis.com/v2/comment/list/',
            $connection,
            ['cursor' => 0, 'count' => 50],
        );

        if ($response === false) {
            return false;
        }

        $comments = [];
        foreach ($response->json('data.comments', []) as $c) {
            $comments[] = [
                'id' => (string) $c['comment_id'],
                'text' => $c['text'] ?? '',
                'author' => $c['user']['display_name'] ?? null,
                'post_id' => $c['video_id'] ?? null,
            ];
        }

        return $comments;
    }

    protected function resolveArticle(array $commentData, Tenant $tenant): ?Article
    {
        return Article::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('platform', 'tiktok')
            ->where('platform_post_id', $commentData['post_id'] ?? null)
            ->first();
    }

    /**
     * TikTok does NOT support hiding comments. This must never be called.
     */
    public function hideComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        return false;
    }

    public function deleteComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token)
            ->post('https://open.tiktokapis.com/v2/comment/delete/', [
                'comment_id' => $commentPlatformId,
            ]);

        return $response->successful();
    }
}
