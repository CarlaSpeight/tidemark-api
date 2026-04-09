<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->integer('total_comments')->default(0);
            $table->integer('auto_approved')->default(0);
            $table->integer('auto_actioned')->default(0);
            $table->integer('queued')->default(0);
            $table->integer('engagement_responses_sent')->default(0);
            $table->decimal('avg_toxicity_score', 5, 2)->nullable();
            $table->decimal('sentiment_positive', 5, 2)->nullable();
            $table->timestamp('calculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_stats');
    }
};
