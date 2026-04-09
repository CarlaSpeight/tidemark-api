<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AbTest extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'article_id',
        'version_a_headline',
        'version_b_headline',
        'version_a_score',
        'version_b_score',
        'winner',
        'concluded_at',
    ];

    protected function casts(): array
    {
        return [
            'concluded_at' => 'datetime',
        ];
    }

    public function article(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
