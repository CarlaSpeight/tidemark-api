<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreatorProfile extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'display_name',
        'platform_handles',
        'content_topics',
        'vibe_shield_level',
        'allow_body_comments',
        'allow_relationship_comments',
        'allow_success_shaming',
        'custom_protection_rules',
        'manager_user_id',
        'weekly_digest_enabled',
        'surge_alert_threshold_multiplier',
    ];

    protected function casts(): array
    {
        return [
            'platform_handles' => 'array',
            'content_topics' => 'array',
            'custom_protection_rules' => 'array',
            'allow_body_comments' => 'boolean',
            'allow_relationship_comments' => 'boolean',
            'allow_success_shaming' => 'boolean',
            'weekly_digest_enabled' => 'boolean',
            'surge_alert_threshold_multiplier' => 'decimal:2',
        ];
    }

    public function setDisplayNameAttribute(string $value): void
    {
        $this->attributes['display_name'] = strip_tags($value);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }
}
