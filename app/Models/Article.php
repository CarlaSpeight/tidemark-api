<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'journalist_id',
        'title',
        'url',
        'platform',
        'platform_post_id',
        'published_at',
        'subject_entities',
        'sensitivity_level',
        'topic_category',
        'comments_enabled',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'subject_entities' => 'array',
            'comments_enabled' => 'boolean',
        ];
    }

    public function journalist(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'journalist_id');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function stat(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ArticleStat::class);
    }

    public function engagementResponses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EngagementResponse::class);
    }

    public function setTitleAttribute(string $value): void
    {
        $this->attributes['title'] = strip_tags($value);
    }
}
