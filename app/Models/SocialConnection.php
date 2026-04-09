<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialConnection extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'platform',
        'platform_page_id',
        'platform_page_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'last_polled_at',
        'is_active',
        'needs_reauth',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'is_active' => 'boolean',
            'needs_reauth' => 'boolean',
        ];
    }

    // Encrypt tokens on set
    public function setAccessTokenAttribute(string $value): void
    {
        $this->attributes['access_token'] = encrypt($value);
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        return $value ? decrypt($value) : null;
    }

    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $value ? encrypt($value) : null;
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        return $value ? decrypt($value) : null;
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }
}
