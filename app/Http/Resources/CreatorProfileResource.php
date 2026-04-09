<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CreatorProfileResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'platform_handles' => $this->platform_handles,
            'content_topics' => $this->content_topics,
            'vibe_shield_level' => $this->vibe_shield_level,
            'allow_body_comments' => $this->allow_body_comments,
            'allow_relationship_comments' => $this->allow_relationship_comments,
            'allow_success_shaming' => $this->allow_success_shaming,
            'custom_protection_rules' => $this->custom_protection_rules,
            'weekly_digest_enabled' => $this->weekly_digest_enabled,
            'surge_alert_threshold_multiplier' => $this->surge_alert_threshold_multiplier,
            'user' => new UserResource($this->whenLoaded('user')),
            'manager_name' => $this->whenLoaded('manager', fn () => $this->manager->name),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
