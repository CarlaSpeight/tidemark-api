<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('journalist_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('url')->nullable();
            $table->enum('platform', [
                'website', 'facebook', 'instagram', 'twitter',
                'youtube', 'tiktok', 'linkedin', 'substack',
            ]);
            $table->string('platform_post_id')->nullable();
            $table->timestamp('published_at');
            $table->jsonb('subject_entities')->nullable();
            $table->enum('sensitivity_level', ['low', 'medium', 'high'])->default('low');
            $table->string('topic_category')->nullable();
            $table->boolean('comments_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
