<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->enum('highlight_type', ['impactful', 'loyal_community', 'funny', 'constructive']);
            $table->boolean('auto_selected')->default(true);
            $table->boolean('pinned')->default(false);
            $table->boolean('saved')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_highlights');
    }
};
