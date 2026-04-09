<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'article_id',
        'platform',
        'original_text',
        'normalised_text',
        'commenter_platform_id',
        'commenter_display_name',
        'toxicity_score',
        'confidence_score',
        'status',
        'routing_decision',
        'flagged_reason',
        'is_personal_attack',
        'target_entity',
        'hidden_at',
        'hidden_by_ai',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'toxicity_score' => 'integer',
            'confidence_score' => 'integer',
            'is_personal_attack' => 'boolean',
            'hidden_at' => 'datetime',
            'hidden_by_ai' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function article(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function moderationActions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ModerationAction::class);
    }

    public function engagementResponse(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EngagementResponse::class);
    }

    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ── Scopes ──

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'journalist' => $query->whereHas('article', fn (Builder $q) => $q->where('journalist_id', $user->id)),
            'section_editor' => $query->whereHas('article', fn (Builder $q) => $q->whereHas('journalist', fn (Builder $u) => $u->where('section', $user->section))),
            default => $query,
        };
    }

    public function scopeNeedsEngagement(Builder $query): Builder
    {
        return $query->where('status', 'approved')
            ->where('toxicity_score', '<', 30)
            ->whereDoesntHave('engagementResponse');
    }

    // ── Helpers ──

    public function isInstagramOrTikTok(): bool
    {
        return in_array($this->platform, ['instagram', 'tiktok']);
    }

    public function canBeHidden(): bool
    {
        return ! $this->isInstagramOrTikTok();
    }

    public function canBeDeleted(): bool
    {
        if ($this->isInstagramOrTikTok()) {
            return true;
        }

        return in_array($this->status, ['hidden', 'confirmed_deleted']);
    }

    public function setOriginalTextAttribute(string $value): void
    {
        $this->attributes['original_text'] = strip_tags($value);
    }

    public function setCommenterDisplayNameAttribute(?string $value): void
    {
        $this->attributes['commenter_display_name'] = $value ? strip_tags($value) : null;
    }
}
