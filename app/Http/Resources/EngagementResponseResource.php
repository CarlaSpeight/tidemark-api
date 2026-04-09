<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EngagementResponseResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'draft_text' => $this->draft_text,
            'final_text' => $this->final_text,
            'response_type' => $this->response_type,
            'status' => $this->status,
            'drafted_by_ai' => $this->drafted_by_ai,
            'approved_at' => $this->approved_at?->toISOString(),
            'posted_at' => $this->posted_at?->toISOString(),
            'comment_preview' => $this->whenLoaded('comment', fn () => [
                'id' => $this->comment->id,
                'original_text' => \Illuminate\Support\Str::limit($this->comment->original_text, 120),
                'commenter_display_name' => $this->comment->commenter_display_name,
            ]),
            'article_title' => $this->whenLoaded('article', fn () => $this->article->title),
            'approver' => new UserResource($this->whenLoaded('approver')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
