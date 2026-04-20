<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescore_requests', function (Blueprint $table) {
            $table->jsonb('visual_context')->nullable()->after('suggestions');
        });
    }

    public function down(): void
    {
        Schema::table('prescore_requests', function (Blueprint $table) {
            $table->dropColumn('visual_context');
        });
    }
};
