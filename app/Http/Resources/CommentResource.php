<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CommentResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'article_id' => $this->article_id,
            'platform' => $this->platform,
            'original_text' => $this->original_text,
            'commenter_display_name' => $this->commenter_display_name,
            'toxicity_score' => $this->toxicity_score,
            'confidence_score' => $this->confidence_score,
            'status' => $this->status,
            'routing_decision' => $this->routing_decision,
            'flagged_reason' => $this->flagged_reason,
            'is_personal_attack' => $this->is_personal_attack,
            'target_entity' => $this->target_entity,
            'hidden_at' => $this->hidden_at?->toISOString(),
            'hidden_by_ai' => $this->hidden_by_ai,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'available_actions' => $this->availableActions(),
            'article' => new ArticleResource($this->whenLoaded('article')),
            'moderation_actions' => ModerationActionResource::collection($this->whenLoaded('moderationActions')),
            'engagement_response' => new EngagementResponseResource($this->whenLoaded('engagementResponse')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function availableActions(): array
    {
        $actions = [];

        if ($this->resource->canBeHidden() && ! in_array($this->status, ['hidden', 'confirmed_deleted', 'deleted'])) {
            $actions[] = 'hide';
        }

        if ($this->resource->canBeDeleted()) {
            $actions[] = 'delete';
        }

        if ($this->status === 'hidden') {
            $actions[] = 'restore';
        }

        if ($this->status === 'pending') {
            $actions[] = 'approve';
        }

        $actions[] = 'escalate';

        return $actions;
    }
}
