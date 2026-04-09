<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'article_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['article_id', 'toxicity_score']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->index(['tenant_id', 'journalist_id']);
            $table->index(['tenant_id', 'published_at']);
        });

        Schema::table('engagement_responses', function (Blueprint $table) {
            $table->index(['tenant_id', 'status']);
            $table->index(['comment_id']);
        });

        Schema::table('moderation_actions', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
            $table->index(['comment_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['tenant_id', 'article_id']);
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['article_id', 'toxicity_score']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'journalist_id']);
            $table->dropIndex(['tenant_id', 'published_at']);
        });

        Schema::table('engagement_responses', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['comment_id']);
        });

        Schema::table('moderation_actions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['comment_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['user_id', 'created_at']);
        });
    }
};
