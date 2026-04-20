<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BaseResource;
use App\Jobs\RetroAnalysePostJob;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class PostHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'platform'      => 'sometimes|string|in:website,facebook,instagram,twitter,youtube,tiktok,linkedin,substack',
            'date_from'     => 'sometimes|date',
            'date_to'       => 'sometimes|date',
            'topic_category'=> 'sometimes|string|max:100',
            'min_toxicity'  => 'sometimes|integer|min:0|max:100',
            'sort'          => 'sometimes|string|in:published_at,avg_toxicity,total_comments',
            'per_page'      => 'sometimes|integer|min:1|max:100',
        ]);

        $query = Article::with('stat')
            ->where('articles.tenant_id', $user->tenant_id);

        // Scope by role — journalists see only their own articles
        if ($user->role === 'journalist' || $user->role === 'creator') {
            $query->where('journalist_id', $user->id);
        }

        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('date_from')) {
            $query->where('published_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('published_at', '<=', $request->date_to);
        }

        if ($request->filled('topic_category')) {
            $query->where('topic_category', $request->topic_category);
        }

        if ($request->filled('min_toxicity')) {
            $query->whereHas('stat', fn ($q) => $q->where('avg_toxicity_score', '>=', $request->min_toxicity));
        }

        $sort = $request->get('sort', 'published_at');

        match ($sort) {
            'avg_toxicity'   => $query->leftJoin('article_stats', 'articles.id', '=', 'article_stats.article_id')
                                       ->orderByDesc('article_stats.avg_toxicity_score'),
            'total_comments' => $query->leftJoin('article_stats', 'articles.id', '=', 'article_stats.article_id')
                                       ->orderByDesc('article_stats.total_comments'),
            default          => $query->orderByDesc('articles.published_at'),
        };

        $paginator = $query->select('articles.*')
            ->paginate($request->integer('per_page', 25));

        $items = $paginator->getCollection()->map(function (Article $article) {
            $stat            = $article->stat;
            $topFlaggedReason = null;

            if ($stat && ($stat->avg_toxicity_score ?? 0) > 50) {
                // Pull most common flagged reason from comments
                $topFlaggedReason = $article->comments()
                    ->whereNotNull('flagged_reason')
                    ->selectRaw('flagged_reason, COUNT(*) as cnt')
                    ->groupBy('flagged_reason')
                    ->orderByDesc('cnt')
                    ->value('flagged_reason');
            }

            return [
                'post_id'             => $article->id,
                'platform'            => $article->platform,
                'headline'            => $article->title,
                'caption'             => $article->caption,
                'published_at'        => $article->published_at?->toISOString(),
                'platform_post_id'    => $article->platform_post_id,
                'avg_toxicity_score'  => $stat ? (int) round($stat->avg_toxicity_score ?? 0) : null,
                'total_comments'      => $stat?->total_comments ?? 0,
                'auto_actioned_count' => $stat?->auto_actioned ?? 0,
                'top_flagged_reason'  => $topFlaggedReason,
                'thumbnail_url'       => $article->thumbnail_url,
                'post_url'            => $article->url,
                'predicted_score'     => $article->predicted_score,
                'risk_level'          => $article->risk_level,
                'topic_category'      => $article->topic_category,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'errors' => [],
        ]);
    }

    public function analyseBatch(Request $request): JsonResponse
    {
        $request->validate([
            'post_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'post_ids.*' => ['integer'],
        ]);

        $user    = $request->user();
        $batchId = (string) Str::uuid();

        $articles = Article::where('tenant_id', $user->tenant_id)
            ->whereIn('id', $request->post_ids)
            ->whereNull('risk_level')
            ->get();

        $count = $articles->count();

        Cache::put("prescore_batch:{$batchId}", [
            'batch_id'   => $batchId,
            'total'      => $count,
            'completed'  => 0,
            'status'     => 'queued',
        ], now()->addHours(2));

        foreach ($articles as $article) {
            RetroAnalysePostJob::dispatch($article, $batchId)->onQueue('reports');
        }

        return BaseResource::success(['queued' => $count, 'batch_id' => $batchId]);
    }
}
