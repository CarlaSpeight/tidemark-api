<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ArticleResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'platform' => $this->platform,
            'platform_post_id' => $this->platform_post_id,
            'published_at' => $this->published_at?->toISOString(),
            'subject_entities' => $this->subject_entities,
            'sensitivity_level' => $this->sensitivity_level,
            'topic_category' => $this->topic_category,
            'comments_enabled' => $this->comments_enabled,
            'journalist' => new UserResource($this->whenLoaded('journalist')),
            'stats' => new ArticleStatResource($this->whenLoaded('stat')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
