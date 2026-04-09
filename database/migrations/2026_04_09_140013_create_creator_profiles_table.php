<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('display_name');
            $table->jsonb('platform_handles');
            $table->jsonb('content_topics');
            $table->enum('vibe_shield_level', ['open', 'filtered', 'protected'])->default('filtered');
            $table->boolean('allow_body_comments')->default(false);
            $table->boolean('allow_relationship_comments')->default(false);
            $table->boolean('allow_success_shaming')->default(false);
            $table->jsonb('custom_protection_rules');
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('weekly_digest_enabled')->default(true);
            $table->decimal('surge_alert_threshold_multiplier')->default(10.0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_profiles');
    }
};
