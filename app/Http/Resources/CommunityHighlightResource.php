<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CommunityHighlightResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'highlight_type' => $this->highlight_type,
            'auto_selected' => $this->auto_selected,
            'pinned' => $this->pinned,
            'saved' => $this->saved,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'comment' => new CommentResource($this->whenLoaded('comment')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
