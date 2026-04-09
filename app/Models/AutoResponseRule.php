<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoResponseRule extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'tone_profile_id',
        'rule_name',
        'trigger_pattern',
        'response_template',
        'use_ai_generation',
        'is_active',
        'times_triggered',
    ];

    protected function casts(): array
    {
        return [
            'trigger_pattern' => 'array',
            'use_ai_generation' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function toneProfile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ToneOfVoiceProfile::class, 'tone_profile_id');
    }
}
