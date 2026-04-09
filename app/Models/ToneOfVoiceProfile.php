<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ToneOfVoiceProfile extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'formality_level',
        'personality_traits',
        'topics_to_avoid',
        'rival_brands_to_avoid',
        'example_responses',
        'custom_instructions',
        'is_active',
        'auto_response_enabled',
        'auto_response_types',
    ];

    protected function casts(): array
    {
        return [
            'personality_traits' => 'array',
            'topics_to_avoid' => 'array',
            'rival_brands_to_avoid' => 'array',
            'example_responses' => 'array',
            'is_active' => 'boolean',
            'auto_response_enabled' => 'boolean',
            'auto_response_types' => 'array',
        ];
    }

    public function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = strip_tags($value);
    }

    public function setCustomInstructionsAttribute(?string $value): void
    {
        $this->attributes['custom_instructions'] = $value ? strip_tags($value) : null;
    }

    public function autoResponseRules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AutoResponseRule::class, 'tone_profile_id');
    }

    public function engagementResponses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EngagementResponse::class, 'tone_profile_id');
    }
}
