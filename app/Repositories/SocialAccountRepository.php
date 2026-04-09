<?php

namespace App\Repositories;

use App\Models\SocialAccount;

class SocialAccountRepository extends BaseRepository
{
    public function __construct(SocialAccount $model)
    {
        parent::__construct($model);
    }

    public function getActiveForTenant(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->with('platform')
            ->get();
    }

    public function findByPlatformAccountId(int $platformId, string $platformAccountId): ?SocialAccount
    {
        return $this->query()
            ->where('platform_id', $platformId)
            ->where('platform_account_id', $platformAccountId)
            ->first();
    }
}
