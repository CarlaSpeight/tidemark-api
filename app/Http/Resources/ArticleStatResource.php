<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ArticleStatResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'total_comments' => $this->total_comments,
            'auto_approved' => $this->auto_approved,
            'auto_actioned' => $this->auto_actioned,
            'queued' => $this->queued,
            'engagement_responses_sent' => $this->engagement_responses_sent,
            'avg_toxicity_score' => $this->avg_toxicity_score,
            'sentiment_positive' => $this->sentiment_positive,
            'calculated_at' => $this->calculated_at?->toISOString(),
        ];
    }
}
