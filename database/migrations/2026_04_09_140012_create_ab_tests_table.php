<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->string('version_a_headline');
            $table->string('version_b_headline');
            $table->integer('version_a_score')->nullable();
            $table->integer('version_b_score')->nullable();
            $table->enum('winner', ['a', 'b'])->nullable();
            $table->timestamp('concluded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ab_tests');
    }
};
