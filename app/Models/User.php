<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'sanctum';

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'section',
        'job_title',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function articles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Article::class, 'journalist_id');
    }

    public function moderationActions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ModerationAction::class, 'moderator_id');
    }

    public function creatorProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CreatorProfile::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
