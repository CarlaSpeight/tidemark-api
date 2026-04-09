<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WellbeingDigestResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'positive_themes' => $this->positive_themes,
            'handled_count' => $this->handled_count,
            'one_highlight' => $this->one_highlight,
            'digest_text' => $this->digest_text,
            'week_start' => $this->week_start?->toDateString(),
            'week_end' => $this->week_end?->toDateString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
