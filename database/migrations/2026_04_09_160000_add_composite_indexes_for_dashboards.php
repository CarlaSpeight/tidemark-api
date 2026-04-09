<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'created_at'], 'comments_tenant_status_created_index');
            $table->index(['article_id', 'status'], 'comments_article_status_index');
        });

        Schema::table('engagement_responses', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'created_at'], 'engagement_responses_tenant_status_created_index');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->index(['tenant_id', 'published_at', 'journalist_id'], 'articles_tenant_published_journalist_index');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_tenant_status_created_index');
            $table->dropIndex('comments_article_status_index');
        });

        Schema::table('engagement_responses', function (Blueprint $table) {
            $table->dropIndex('engagement_responses_tenant_status_created_index');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex('articles_tenant_published_journalist_index');
        });
    }
};
