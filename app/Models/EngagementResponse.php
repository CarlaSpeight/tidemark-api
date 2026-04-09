<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EngagementResponse extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id',
        'comment_id',
        'article_id',
        'platform',
        'draft_text',
        'final_text',
        'response_type',
        'tone_profile_id',
        'status',
        'drafted_by_ai',
        'approved_by',
        'approved_at',
        'posted_at',
        'platform_response_id',
    ];

    protected function casts(): array
    {
        return [
            'drafted_by_ai' => 'boolean',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function setDraftTextAttribute(string $value): void
    {
        $this->attributes['draft_text'] = strip_tags($value);
    }

    public function setFinalTextAttribute(?string $value): void
    {
        $this->attributes['final_text'] = $value ? strip_tags($value) : null;
    }

    public function comment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function article(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function toneProfile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ToneOfVoiceProfile::class, 'tone_profile_id');
    }

    public function approver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
