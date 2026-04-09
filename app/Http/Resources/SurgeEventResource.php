<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SurgeEventResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_platform_id' => $this->post_platform_id,
            'detected_at' => $this->detected_at?->toISOString(),
            'comment_rate_multiplier' => $this->comment_rate_multiplier,
            'negative_sentiment_pct' => $this->negative_sentiment_pct,
            'new_account_pct' => $this->new_account_pct,
            'coordinated_phrases' => $this->coordinated_phrases,
            'action_taken' => $this->action_taken,
            'creator_notified' => $this->creator_notified,
            'creator_stepped_away' => $this->creator_stepped_away,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
