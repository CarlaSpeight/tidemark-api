<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('post_platform_id');
            $table->timestamp('detected_at');
            $table->decimal('comment_rate_multiplier');
            $table->decimal('negative_sentiment_pct');
            $table->decimal('new_account_pct')->nullable();
            $table->jsonb('coordinated_phrases')->nullable();
            $table->enum('action_taken', ['alert', 'paused_comments', 'deleted_coordinated']);
            $table->boolean('creator_notified')->default(false);
            $table->boolean('creator_stepped_away')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surge_events');
    }
};
