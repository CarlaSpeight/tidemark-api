<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ModerationActionResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'comment_id' => $this->comment_id,
            'action' => $this->action,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'moderator' => new UserResource($this->whenLoaded('moderator')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
