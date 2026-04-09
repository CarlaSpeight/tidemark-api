<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleStat extends Model
{
    use HasFactory, HasTenant;

    public $timestamps = false;

    protected $fillable = [
        'article_id',
        'tenant_id',
        'total_comments',
        'auto_approved',
        'auto_actioned',
        'queued',
        'engagement_responses_sent',
        'avg_toxicity_score',
        'sentiment_positive',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'avg_toxicity_score' => 'decimal:2',
            'sentiment_positive' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }

    public function article(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
