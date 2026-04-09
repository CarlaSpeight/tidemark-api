<?php

namespace App\Jobs;

use App\Models\ToneOfVoiceProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrainToneProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [15, 60];

    public function __construct(public ToneOfVoiceProfile $profile)
    {
    }

    public function handle(): void
    {
        $profile = $this->profile;

        $response = Http::timeout(30)
            ->post(config('services.tidemark_ai.url') . '/engagement/train', [
                'profile_id' => $profile->id,
                'name' => $profile->name,
                'formality_level' => $profile->formality_level,
                'personality_traits' => $profile->personality_traits,
                'topics_to_avoid' => $profile->topics_to_avoid,
                'rival_brands_to_avoid' => $profile->rival_brands_to_avoid,
                'example_responses' => $profile->example_responses,
                'custom_instructions' => $profile->custom_instructions,
            ]);

        if ($response->failed()) {
            Log::warning('TrainToneProfileJob failed', [
                'profile_id' => $profile->id,
                'status' => $response->status(),
            ]);

            return;
        }

        // Store profile in cache for fast access during draft generation
        Cache::put(
            "tone_profile:{$profile->tenant_id}:{$profile->id}",
            $profile->toArray(),
            now()->addDays(7),
        );

        Log::info('Tone profile trained and cached', [
            'profile_id' => $profile->id,
        ]);
    }
}
