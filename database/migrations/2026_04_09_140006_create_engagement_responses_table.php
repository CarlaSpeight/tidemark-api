<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->enum('platform', [
                'website', 'facebook', 'instagram', 'twitter',
                'youtube', 'tiktok', 'linkedin', 'substack',
            ]);
            $table->text('draft_text');
            $table->text('final_text')->nullable();
            $table->enum('response_type', [
                'factual_answer', 'positive_acknowledgement', 'debate_prompt',
                'expert_elevation', 'complaint_empathy', 'complaint_editorial', 'auto_rule',
            ]);
            // FK to tone_of_voice_profiles added in migration 7 after that table exists
            $table->unsignedBigInteger('tone_profile_id')->nullable();
            $table->enum('status', ['draft', 'approved', 'posted', 'rejected', 'auto_posted'])->default('draft');
            $table->boolean('drafted_by_ai')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('platform_response_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_responses');
    }
};
