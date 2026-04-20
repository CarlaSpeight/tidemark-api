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

    public const ROLE_DISPLAY_NAMES = [
        'editor' => 'Editor',
        'deputy_editor' => 'Deputy Editor',
        'senior_reporter' => 'Senior Reporter',
        'journalist' => 'Journalist',
        'producer' => 'Producer',
        'creator' => 'Creator',
        'agent' => 'Agent',
        'super_admin' => 'Super Admin',
        'cs_manager' => 'CS Manager',
        'cs_agent' => 'CS Agent',
        'friends_family' => 'Friends & Family',
    ];

    private const ROLE_RANK = [
        'friends_family' => 0,
        'producer' => 1,
        'journalist' => 1,
        'senior_reporter' => 1,
        'creator' => 1,
        'cs_agent' => 1,
        'deputy_editor' => 2,
        'agent' => 2,
        'cs_manager' => 2,
        'editor' => 3,
        'super_admin' => 99,
    ];

    protected $guard_name = 'sanctum';

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'user_account_type',
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

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->role) {
                $user->user_account_type = self::accountTypeForRole($user->role);
            }
        });
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
        return $this->role === 'super_admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAtLeast(string $role): bool
    {
        $current = self::ROLE_RANK[$this->role] ?? -1;
        $target = self::ROLE_RANK[$role] ?? -1;

        if ($this->isSuperAdmin()) {
            return true;
        }

        return $current >= $target;
    }

    public static function accountTypeForRole(string $role): string
    {
        if (in_array($role, ['editor', 'deputy_editor', 'senior_reporter', 'journalist', 'producer'], true)) {
            return 'publication_staff';
        }

        if (in_array($role, ['creator', 'agent'], true)) {
            return 'creator';
        }

        return 'tidemark_staff';
    }

    public function roleDisplayName(): string
    {
        return self::ROLE_DISPLAY_NAMES[$this->role] ?? ucfirst(str_replace('_', ' ', $this->role));
    }
}
