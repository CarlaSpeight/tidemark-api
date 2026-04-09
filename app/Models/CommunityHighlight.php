<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityHighlight extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'creator_id',
        'comment_id',
        'highlight_type',
        'auto_selected',
        'pinned',
        'saved',
    ];

    protected function casts(): array
    {
        return [
            'auto_selected' => 'boolean',
            'pinned' => 'boolean',
            'saved' => 'boolean',
        ];
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function comment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
