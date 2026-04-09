<?php

namespace App\Services\SocialMedia;

use App\Models\Article;
use App\Models\SocialConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

class LinkedInService extends AbstractPlatformService
{
    protected function fetchComments(SocialConnection $connection, string $since): array|false
    {
        $response = $this->makeRequest(
            'get',
            "https://api.linkedin.com/v2/socialActions/urn:li:organization:{$connection->platform_page_id}/comments",
            $connection,
        );

        if ($response === false) {
            return false;
        }

        $comments = [];
        foreach ($response->json('elements', []) as $c) {
            $comments[] = [
                'id' => $c['$URN'] ?? $c['id'] ?? '',
                'text' => $c['message']['text'] ?? '',
                'author' => $c['actor'] ?? null,
                'post_id' => $c['object'] ?? null,
            ];
        }

        return $comments;
    }

    protected function resolveArticle(array $commentData, Tenant $tenant): ?Article
    {
        return Article::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('platform', 'linkedin')
            ->where('platform_post_id', $commentData['post_id'] ?? null)
            ->first();
    }

    public function hideComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        // LinkedIn doesn't have a native hide; treat as delete
        return $this->deleteComment($commentPlatformId, $connection);
    }

    public function deleteComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token)
            ->delete("https://api.linkedin.com/v2/socialActions/{$commentPlatformId}");

        return $response->successful();
    }
}
