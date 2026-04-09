<?php

namespace App\Services;

use App\Models\CommunityHighlight;
use App\Models\CreatorProfile;
use App\Models\SurgeEvent;
use App\Models\User;
use App\Models\WellbeingDigest;
use App\Traits\Auditable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreatorService
{
    use Auditable;

    // ── Profile ──────────────────────────────────────────────

    public function getProfile(User $user): CreatorProfile
    {
        $profile = $this->resolveProfile($user);

        return $profile->load(['user', 'manager']);
    }

    public function updateProfile(User $user, array $data): CreatorProfile
    {
        $profile = $this->resolveProfile($user);

        $profile->update($data);

        static::audit('creator_profile_updated', 'creator_profile', $profile->id, 'Creator protection settings updated');

        return $profile->load(['user', 'manager']);
    }

    // ── Step away ────────────────────────────────────────────

    public function stepAway(SurgeEvent $event, User $user): void
    {
        $event->update(['creator_stepped_away' => true]);

        $profile = $this->resolveProfile($user);
        $previousLevel = $profile->vibe_shield_level;
        $profile->update(['vibe_shield_level' => 'protected']);

        static::audit('creator_stepped_away', 'surge_event', $event->id, 'Creator stepped away during surge event', [
            'previous_vibe_shield_level' => $previousLevel,
        ]);

        // Notify manager if set
        if ($profile->manager_user_id) {
            Log::info('Creator stepped away — notifying manager', [
                'creator_id' => $user->id,
                'manager_id' => $profile->manager_user_id,
                'surge_event_id' => $event->id,
            ]);
        }

        // Schedule return notification (24h later — handled by scheduler/queue)
        Log::info('Scheduling ready-to-return notification in 24 hours', [
            'creator_id' => $user->id,
            'surge_event_id' => $event->id,
        ]);
    }

    // ── Community highlights ─────────────────────────────────

    public function getHighlights(User $user): \Illuminate\Support\Collection
    {
        $profile = $this->resolveProfile($user);

        $baseUrl = rtrim(config('services.tidemark_ai.url', 'http://localhost:8001'), '/');

        try {
            $response = Http::timeout(15)->post("{$baseUrl}/creator/highlights", [
                'creator_id' => $user->id,
                'tenant_id' => $user->tenant_id,
            ]);

            if (! $response->successful()) {
                Log::warning('tidemark-ai highlights request failed', [
                    'status' => $response->status(),
                    'creator_id' => $user->id,
                ]);

                return $this->fallbackHighlights($user);
            }

            $highlights = collect();
            foreach ($response->json('highlights', []) as $item) {
                $highlight = CommunityHighlight::withoutGlobalScope('tenant')->updateOrCreate(
                    [
                        'tenant_id' => $user->tenant_id,
                        'creator_id' => $user->id,
                        'comment_id' => $item['comment_id'],
                    ],
                    [
                        'highlight_type' => $item['highlight_type'] ?? 'impactful',
                        'auto_selected' => true,
                    ],
                );
                $highlight->load('comment');
                $highlights->push($highlight);
            }

            return $highlights;
        } catch (\Exception $e) {
            Log::error('tidemark-ai highlights error', [
                'error' => $e->getMessage(),
                'creator_id' => $user->id,
            ]);

            return $this->fallbackHighlights($user);
        }
    }

    public function pinHighlight(CommunityHighlight $highlight): void
    {
        $highlight->update(['pinned' => true]);
    }

    public function saveHighlight(CommunityHighlight $highlight): void
    {
        $highlight->update(['saved' => true]);
    }

    // ── Weekly digest ────────────────────────────────────────

    public function getWeeklyDigest(User $user): ?WellbeingDigest
    {
        return WellbeingDigest::withoutGlobalScope('tenant')
            ->where('creator_id', $user->id)
            ->orderByDesc('week_end')
            ->first();
    }

    // ── Helpers ──────────────────────────────────────────────

    private function resolveProfile(User $user): CreatorProfile
    {
        // Creator sees own profile; manager sees their managed creator's profile
        if ($user->role === 'creator_manager') {
            $profile = CreatorProfile::withoutGlobalScope('tenant')
                ->where('manager_user_id', $user->id)
                ->firstOrFail();
        } else {
            $profile = CreatorProfile::withoutGlobalScope('tenant')
                ->where('user_id', $user->id)
                ->firstOrFail();
        }

        return $profile;
    }

    private function fallbackHighlights(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return CommunityHighlight::withoutGlobalScope('tenant')
            ->where('creator_id', $user->id)
            ->with('comment')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();
    }
}
