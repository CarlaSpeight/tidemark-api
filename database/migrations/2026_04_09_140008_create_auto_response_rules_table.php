<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_response_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tone_profile_id')->constrained('tone_of_voice_profiles')->cascadeOnDelete();
            $table->string('rule_name');
            $table->jsonb('trigger_pattern');
            $table->text('response_template')->nullable();
            $table->boolean('use_ai_generation')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('times_triggered')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_response_rules');
    }
};
