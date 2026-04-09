<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tone_of_voice_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->enum('formality_level', [
                'very_formal', 'formal', 'neutral', 'informal', 'very_informal',
            ]);
            $table->jsonb('personality_traits');
            $table->jsonb('topics_to_avoid');
            $table->jsonb('rival_brands_to_avoid');
            $table->jsonb('example_responses');
            $table->text('custom_instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_response_enabled')->default(false);
            $table->jsonb('auto_response_types')->nullable();
            $table->timestamps();
        });

        // Now wire the deferred FK from engagement_responses
        Schema::table('engagement_responses', function (Blueprint $table) {
            $table->foreign('tone_profile_id')
                ->references('id')
                ->on('tone_of_voice_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('engagement_responses', function (Blueprint $table) {
            $table->dropForeign(['tone_profile_id']);
        });

        Schema::dropIfExists('tone_of_voice_profiles');
    }
};
