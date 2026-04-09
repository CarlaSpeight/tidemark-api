<?php

namespace App\Services;

use App\Repositories\SocialAccountRepository;
use App\Traits\Auditable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SocialAccountService
{
    use Auditable;

    public function __construct(
        private SocialAccountRepository $repository,
    ) {
    }

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage);
    }

    public function show(string $uuid): \App\Models\SocialAccount
    {
        return $this->repository->findByUuid($uuid);
    }

    public function create(array $data): \App\Models\SocialAccount
    {
        $account = $this->repository->create($data);

        self::audit('social_account_created', 'social_account', $account->id);

        return $account;
    }

    public function update(\App\Models\SocialAccount $account, array $data): \App\Models\SocialAccount
    {
        $old = $account->only(array_keys($data));
        $account = $this->repository->update($account, $data);

        self::audit('social_account_updated', 'social_account', $account->id, oldValues: $old, newValues: $data);

        return $account;
    }

    public function delete(\App\Models\SocialAccount $account): void
    {
        self::audit('social_account_deleted', 'social_account', $account->id);

        $this->repository->delete($account);
    }
}
