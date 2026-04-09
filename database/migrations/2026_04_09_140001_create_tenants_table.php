<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->jsonb('settings')->nullable();
            $table->enum('subscription_tier', [
                'trial', 'starter', 'professional', 'enterprise',
                'creator_free', 'creator_pro', 'creator_studio', 'creator_agency',
            ])->default('trial');
            $table->enum('product_tier', ['media', 'creator', 'both'])->default('media');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
