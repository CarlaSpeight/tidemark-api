<?php

namespace App\Services\Website;

use App\Jobs\ClassifyCommentJob;
use App\Models\Article;
use App\Models\Comment;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function handleIncoming(Request $request, Tenant $tenant): void
    {
        $this->validateSignature($request, $tenant);

        $data = $request->validate([
            'article_url' => ['required', 'string', 'max:2048'],
            'comment_text' => ['required', 'string', 'max:10000'],
            'commenter_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'commenter_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $article = Article::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('platform', 'website')
            ->where('url', $data['article_url'])
            ->first();

        if (! $article) {
            Log::warning('Webhook received for unknown article', [
                'tenant_id' => $tenant->id,
                'url' => $data['article_url'],
            ]);

            return;
        }

        $comment = Comment::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'article_id' => $article->id,
            'platform' => 'website',
            'original_text' => $data['comment_text'],
            'commenter_platform_id' => $data['commenter_id'] ?? null,
            'commenter_display_name' => $data['commenter_name'] ?? null,
            'status' => 'pending',
        ]);

        ClassifyCommentJob::dispatch($comment)->onQueue('comments-high');
    }

    private function validateSignature(Request $request, Tenant $tenant): void
    {
        $signature = $request->header('X-Tidemark-Signature');

        if (! $signature) {
            abort(401, 'Missing webhook signature.');
        }

        $secret = config('services.tidemark.webhook_secret');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }
    }
}
