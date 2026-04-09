<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'settings',
        'subscription_tier',
        'product_tier',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function users(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class);
    }

    public function socialConnections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SocialConnection::class);
    }

    public function articles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function toneOfVoiceProfiles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ToneOfVoiceProfile::class);
    }

    public function isMedia(): bool
    {
        return in_array($this->product_tier, ['media', 'both']);
    }

    public function isCreator(): bool
    {
        return in_array($this->product_tier, ['creator', 'both']);
    }
}
