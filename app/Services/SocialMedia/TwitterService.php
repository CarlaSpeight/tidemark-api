<?php

namespace App\Services\SocialMedia;

use App\Models\Article;
use App\Models\SocialConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

class TwitterService extends AbstractPlatformService
{
    protected function fetchComments(SocialConnection $connection, string $since): array|false
    {
        $response = $this->makeRequest(
            'get',
            'https://api.twitter.com/2/tweets/search/recent',
            $connection,
            [
                'query' => "conversation_id:{$connection->platform_page_id}",
                'start_time' => $since,
                'tweet.fields' => 'author_id,conversation_id,created_at,text',
                'expansions' => 'author_id',
            ],
        );

        if ($response === false) {
            return false;
        }

        $users = collect($response->json('includes.users', []))->keyBy('id');

        $comments = [];
        foreach ($response->json('data', []) as $tweet) {
            $comments[] = [
                'id' => $tweet['id'],
                'text' => $tweet['text'],
                'author' => $users[$tweet['author_id']]['username'] ?? null,
                'post_id' => $tweet['conversation_id'] ?? null,
            ];
        }

        return $comments;
    }

    protected function resolveArticle(array $commentData, Tenant $tenant): ?Article
    {
        return Article::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('platform', 'twitter')
            ->where('platform_post_id', $commentData['post_id'] ?? null)
            ->first();
    }

    public function hideComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token)
            ->put("https://api.twitter.com/2/tweets/{$commentPlatformId}/hidden", [
                'hidden' => true,
            ]);

        return $response->successful();
    }

    public function deleteComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token)
            ->delete("https://api.twitter.com/2/tweets/{$commentPlatformId}");

        return $response->successful();
    }
}
