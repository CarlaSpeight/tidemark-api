<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SurgeEvent extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'creator_id',
        'post_platform_id',
        'detected_at',
        'comment_rate_multiplier',
        'negative_sentiment_pct',
        'new_account_pct',
        'coordinated_phrases',
        'action_taken',
        'creator_notified',
        'creator_stepped_away',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'comment_rate_multiplier' => 'decimal:2',
            'negative_sentiment_pct' => 'decimal:2',
            'new_account_pct' => 'decimal:2',
            'coordinated_phrases' => 'array',
            'creator_notified' => 'boolean',
            'creator_stepped_away' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
