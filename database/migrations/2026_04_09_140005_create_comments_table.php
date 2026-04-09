<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->enum('platform', [
                'website', 'facebook', 'instagram', 'twitter',
                'youtube', 'tiktok', 'linkedin', 'substack',
            ]);
            $table->text('original_text');
            $table->text('normalised_text')->nullable();
            $table->string('commenter_platform_id')->nullable();
            $table->string('commenter_display_name')->nullable();
            $table->integer('toxicity_score')->nullable();
            $table->integer('confidence_score')->nullable();
            $table->enum('status', [
                'pending', 'approved', 'hidden', 'restored',
                'confirmed_deleted', 'deleted',
            ])->default('pending');
            $table->enum('routing_decision', ['approve', 'hide', 'delete', 'queue'])->nullable();
            $table->string('flagged_reason')->nullable();
            $table->boolean('is_personal_attack')->default(false);
            $table->string('target_entity')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->boolean('hidden_by_ai')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
