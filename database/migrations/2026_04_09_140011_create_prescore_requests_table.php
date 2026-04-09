<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescore_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('journalist_id')->constrained('users')->cascadeOnDelete();
            $table->string('headline');
            $table->text('caption')->nullable();
            $table->string('topic_category');
            $table->integer('predicted_score');
            $table->enum('risk_level', ['low', 'medium', 'high']);
            $table->jsonb('suggestions')->nullable();
            $table->string('accepted_suggestion')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescore_requests');
    }
};
