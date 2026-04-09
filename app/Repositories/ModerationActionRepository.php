<?php

namespace App\Repositories;

use App\Models\ModerationAction;

class ModerationActionRepository extends BaseRepository
{
    public function __construct(ModerationAction $model)
    {
        parent::__construct($model);
    }

    public function getForComment(int $commentId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->query()
            ->where('comment_id', $commentId)
            ->with('user')
            ->latest()
            ->get();
    }
}
