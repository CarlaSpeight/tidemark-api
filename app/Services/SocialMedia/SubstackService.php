<?php

namespace App\Services\SocialMedia;

use App\Models\SocialConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class SubstackService implements PlatformServiceInterface
{
    public function pollNewComments(SocialConnection $connection, Tenant $tenant): void
    {
        Log::info('Substack moderation is not available via API — skipping poll.', [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function hideComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        return false;
    }

    public function deleteComment(string $commentPlatformId, SocialConnection $connection): bool
    {
        return false;
    }
}
