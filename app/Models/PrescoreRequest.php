<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrescoreRequest extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'journalist_id',
        'headline',
        'caption',
        'topic_category',
        'predicted_score',
        'risk_level',
        'suggestions',
        'accepted_suggestion',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'suggestions' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function journalist(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'journalist_id');
    }
}
