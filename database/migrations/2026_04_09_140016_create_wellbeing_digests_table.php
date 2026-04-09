<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wellbeing_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->jsonb('positive_themes');
            $table->unsignedInteger('handled_count')->default(0);
            $table->text('one_highlight')->nullable();
            $table->text('digest_text');
            $table->date('week_start');
            $table->date('week_end');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellbeing_digests');
    }
};
