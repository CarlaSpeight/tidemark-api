<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->text('caption')->nullable()->after('title');
            $table->string('thumbnail_url')->nullable()->after('url');
            $table->integer('predicted_score')->nullable()->after('sensitivity_level');
            $table->enum('risk_level', ['low', 'medium', 'high'])->nullable()->after('predicted_score');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['caption', 'thumbnail_url', 'predicted_score', 'risk_level']);
        });
    }
};
