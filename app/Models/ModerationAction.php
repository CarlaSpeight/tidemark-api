<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModerationAction extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'comment_id',
        'moderator_id',
        'action',
        'reason',
        'notes',
    ];

    public function comment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function moderator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }
}
