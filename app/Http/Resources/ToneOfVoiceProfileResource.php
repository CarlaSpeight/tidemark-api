<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ToneOfVoiceProfileResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'formality_level' => $this->formality_level,
            'personality_traits' => $this->personality_traits,
            'topics_to_avoid' => $this->topics_to_avoid,
            'rival_brands_to_avoid' => $this->rival_brands_to_avoid,
            'example_responses' => $this->example_responses,
            'custom_instructions' => $this->custom_instructions,
            'is_active' => $this->is_active,
            'auto_response_enabled' => $this->auto_response_enabled,
            'auto_response_types' => $this->auto_response_types,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
