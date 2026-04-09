<?php

namespace App\Services\SocialMedia;

use App\Models\Article;
use App\Models\SocialConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

class FacebookService extends AbstractPlatformService
{
    protected function fetchComments(SocialConnection $connection, string $since): array|false
    {
        $response = $this->makeRequest(
            'get',
            "https://graph.facebook.com/v19.0/{$connection->platform_page_id}/feed",
            $connection,
            ['fields' => 'comments{id,message,from,created_time}', 'since' => $since],
        );

        if ($response === false) {
            return false;
        }

        $comments = [];
        foreach ($response->json('data', []) as $post) {
            foreach ($post['comments']['data'] ?? [] as $c) {
                $comments[] = [
                    'id' => $c['id'],
                    'text' => $c['message'],
                    'author' => $c['from']['name'] ?? null,
                    'post_id' => $post['id'] ?? null,
                ];
            }
        }

        return $comments;
    }

    protected function resolveArticle(array $commentData, Tenant $tenant): ?Article
    {
        return Article::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('platform', 'facebook')
            ->where('platform_post_id', $commentData['post_id'] ?? null)
            ->first();
    }

    public function hideComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token)
            ->post("https://graph.facebook.com/v19.0/{$commentPlatformId}", [
                'is_hidden' => true,
            ]);

        return $response->successful();
    }

    public function deleteComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token)
            ->delete("https://graph.facebook.com/v19.0/{$commentPlatformId}");

        return $response->successful();
    }
}
