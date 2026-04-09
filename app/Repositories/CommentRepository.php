<?php

namespace App\Repositories;

use App\Models\Comment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CommentRepository extends BaseRepository
{
    public function __construct(Comment $model)
    {
        parent::__construct($model);
    }

    public function getFiltered(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['platform_id']), fn (Builder $q) => $q->where('platform_id', $filters['platform_id']))
            ->when(isset($filters['social_account_id']), fn (Builder $q) => $q->where('social_account_id', $filters['social_account_id']))
            ->when(isset($filters['min_toxicity']), fn (Builder $q) => $q->where('toxicity_score', '>=', $filters['min_toxicity']))
            ->when(isset($filters['search']), fn (Builder $q) => $q->where('body', 'ilike', '%' . $filters['search'] . '%'))
            ->with(['platform', 'socialAccount'])
            ->latest()
            ->paginate($perPage);
    }

    public function getPendingForAccount(int $socialAccountId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->query()
            ->where('social_account_id', $socialAccountId)
            ->where('status', 'pending')
            ->get();
    }
}
