<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('platform', [
                'website', 'facebook', 'instagram', 'twitter',
                'youtube', 'tiktok', 'linkedin', 'substack',
            ]);
            $table->string('platform_page_id');
            $table->string('platform_page_name');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_reauth')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_connections');
    }
};
