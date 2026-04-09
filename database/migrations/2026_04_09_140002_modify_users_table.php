<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->after('id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('role', [
                'journalist', 'section_editor', 'senior_editor', 'admin',
                'creator', 'creator_manager',
            ])->after('password');
            $table->string('section')->nullable()->after('role');
            $table->string('job_title')->nullable()->after('section');
            $table->boolean('is_active')->default(true)->after('job_title');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'role', 'section', 'job_title', 'is_active']);
        });
    }
};
