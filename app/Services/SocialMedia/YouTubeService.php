<?php

namespace App\Services\SocialMedia;

use App\Models\Article;
use App\Models\SocialConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

class YouTubeService extends AbstractPlatformService
{
    protected function fetchComments(SocialConnection $connection, string $since): array|false
    {
        $response = $this->makeRequest(
            'get',
            'https://www.googleapis.com/youtube/v3/commentThreads',
            $connection,
            [
                'allThreadsRelatedToChannelId' => $connection->platform_page_id,
                'part' => 'snippet',
                'maxResults' => 100,
                'order' => 'time',
            ],
        );

        if ($response === false) {
            return false;
        }

        $comments = [];
        foreach ($response->json('items', []) as $item) {
            $snippet = $item['snippet']['topLevelComment']['snippet'] ?? [];
            $comments[] = [
                'id' => $item['snippet']['topLevelComment']['id'] ?? $item['id'],
                'text' => $snippet['textDisplay'] ?? '',
                'author' => $snippet['authorDisplayName'] ?? null,
                'post_id' => $item['snippet']['videoId'] ?? null,
            ];
        }

        return $comments;
    }

    protected function resolveArticle(array $commentData, Tenant $tenant): ?Article
    {
        return Article::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('platform', 'youtube')
            ->where('platform_post_id', $commentData['post_id'] ?? null)
            ->first();
    }

    public function hideComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token)
            ->post('https://www.googleapis.com/youtube/v3/comments/setModerationStatus', [
                'id' => $commentPlatformId,
                'moderationStatus' => 'heldForReview',
            ]);

        return $response->successful();
    }

    public function deleteComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token)
            ->delete('https://www.googleapis.com/youtube/v3/comments', [
                'id' => $commentPlatformId,
            ]);

        return $response->successful();
    }
}
