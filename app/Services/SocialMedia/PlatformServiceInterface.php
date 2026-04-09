<?php

namespace App\Services\SocialMedia;

use App\Models\SocialConnection;
use App\Models\Tenant;

interface PlatformServiceInterface
{
    public function pollNewComments(SocialConnection $connection, Tenant $tenant): void;

    public function hideComment(string $commentPlatformId, SocialConnection $connection): bool;

    public function deleteComment(string $commentPlatformId, SocialConnection $connection): bool;
}
