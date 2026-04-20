<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PrescoreHeadlineRequest;
use App\Http\Resources\BaseResource;
use App\Models\PrescoreRequest;
use App\Traits\Auditable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PrescoreController extends Controller
{
    use Auditable;

    private const TENANT_HISTORY_LIMIT = 20;

    public function analyse(PrescoreHeadlineRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // ── Visual content handling ──────────────────────────
        $imageData   = null;
        $imageUrl    = null;

        if ($request->hasFile('image')) {
            $imageData = base64_encode(
                file_get_contents($request->file('image')->getRealPath())
            );
        } elseif (! empty($data['image_url'])) {
            $imageUrl = $data['image_url'];
        } elseif (! empty($data['video_thumbnail_url'])) {
            $imageUrl = $data['video_thumbnail_url'];
        }

        // ── Creator profile (passed to AI for context) ───────
        $creatorProfile = null;
        if ($user->hasRole('creator') && $user->creatorProfile) {
            $creatorProfile = [
                'allow_body_comments'         => $user->creatorProfile->allow_body_comments,
                'allow_relationship_comments' => $user->creatorProfile->allow_relationship_comments,
                'allow_success_shaming'        => $user->creatorProfile->allow_success_shaming,
                'vibe_shield_level'            => $user->creatorProfile->vibe_shield_level,
            ];
        }

        // ── Tenant history for context ───────────────────────
        $tenantHistory = PrescoreRequest::where('tenant_id', $user->tenant_id)
            ->orderByDesc('created_at')
            ->limit(self::TENANT_HISTORY_LIMIT)
            ->get(['headline', 'topic_category', 'predicted_score'])
            ->map(fn ($r) => [
                'headline'  => $r->headline,
                'topic'     => $r->topic_category,
                'score'     => $r->predicted_score,
            ])
            ->toArray();

        // ── Call tidemark-ai ─────────────────────────────────
        $aiUrl   = rtrim(config('services.tidemark_ai.url', 'http://localhost:8001'), '/');
        $payload = [
            'headline'        => $data['headline'],
            'caption'         => $data['caption'] ?? null,
            'topic'           => $data['topic'],
            'tenant_history'  => $tenantHistory,
            'image_data'      => $imageData,
            'image_url'       => $imageUrl,
            'creator_profile' => $creatorProfile,
        ];

        try {
            $response = Http::timeout(30)
                ->withHeaders(['X-Internal-Key' => config('services.tidemark_ai.key', '')])
                ->post("{$aiUrl}/prescore-headline", $payload);

            if (! $response->successful()) {
                Log::warning('tidemark-ai prescore failed', ['status' => $response->status()]);

                return BaseResource::error([['message' => 'Analysis service unavailable. Please try again.']], 503);
            }

            $result = $response->json();
        } catch (\Throwable $e) {
            Log::error('tidemark-ai prescore exception', ['error' => $e->getMessage()]);

            return BaseResource::error([['message' => 'Analysis service unavailable. Please try again.']], 503);
        }

        // ── Persist result ───────────────────────────────────
        $prescoreRecord = PrescoreRequest::create([
            'tenant_id'      => $user->tenant_id,
            'journalist_id'  => $user->id,
            'headline'       => strip_tags($data['headline']),
            'caption'        => isset($data['caption']) ? strip_tags($data['caption']) : null,
            'topic_category' => $data['topic'],
            'predicted_score'=> $result['predicted_score'] ?? 0,
            'risk_level'     => $result['risk_level'] ?? 'low',
            'suggestions'    => $result['suggestions'] ?? [],
            'visual_context' => $result['visual_analysis'] ?? null,
        ]);

        self::audit('prescore_analysed', 'prescore_request', $prescoreRecord->id,
            description: 'Headline risk checked',
            extra: ['risk_level' => $result['risk_level'] ?? 'low', 'has_visual' => $imageData || $imageUrl],
        );

        return BaseResource::success([
            'toxicity_score'   => $result['predicted_score'],
            'risk_level'       => $result['risk_level'],
            'risk_reasons'     => $result['risk_reasons'] ?? [],
            'alternatives'     => collect($result['suggestions'] ?? [])->map(fn ($s) => [
                'headline'       => $s['headline'],
                'predicted_score'=> $s['predicted_score'] ?? $s['predicted_score'] ?? 50,
                'reduction_pct'  => $s['improvement_pct'] ?? $s['reduction_pct'] ?? 0,
            ])->values()->all(),
            'visual_analysis'  => $result['visual_analysis'] ?? null,
            'was_normalised'   => $result['was_normalised'] ?? false,
        ]);
    }

    public function batchStatus(string $batchId): JsonResponse
    {
        $status = Cache::get("prescore_batch:{$batchId}");

        if (! $status) {
            return BaseResource::error([['message' => 'Batch not found.']], 404);
        }

        return BaseResource::success($status);
    }
}
