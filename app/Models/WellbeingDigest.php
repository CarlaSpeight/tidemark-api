<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WellbeingDigest extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'creator_id',
        'positive_themes',
        'handled_count',
        'one_highlight',
        'digest_text',
        'week_start',
        'week_end',
    ];

    protected function casts(): array
    {
        return [
            'positive_themes' => 'array',
            'week_start' => 'date',
            'week_end' => 'date',
        ];
    }

    public function setOneHighlightAttribute(?string $value): void
    {
        $this->attributes['one_highlight'] = $value ? strip_tags($value) : null;
    }

    public function setDigestTextAttribute(?string $value): void
    {
        $this->attributes['digest_text'] = $value ? strip_tags($value) : null;
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
