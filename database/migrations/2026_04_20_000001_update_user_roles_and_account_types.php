<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $roleMap = [
            'journalist' => 'journalist',
            'section_editor' => 'deputy_editor',
            'senior_editor' => 'editor',
            'admin' => 'super_admin',
            'creator' => 'creator',
            'creator_manager' => 'agent',
        ];

        $newRoles = [
            'editor',
            'deputy_editor',
            'senior_reporter',
            'journalist',
            'producer',
            'creator',
            'agent',
            'super_admin',
            'cs_manager',
            'cs_agent',
            'friends_family',
        ];

        DB::transaction(function () use ($newRoles, $roleMap): void {
            if (! Schema::hasColumn('users', 'user_account_type')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->enum('user_account_type', ['publication_staff', 'creator', 'tidemark_staff'])
                        ->nullable()
                        ->after('role');
                });
            }

            Schema::table('users', function (Blueprint $table) use ($newRoles) {
                $table->enum('role_new', $newRoles)->nullable()->after('role');
            });

            $quotedRoleCase = "CASE role\n";
            foreach ($roleMap as $old => $new) {
                $quotedRoleCase .= "WHEN '{$old}' THEN '{$new}'\n";
            }
            $quotedRoleCase .= 'ELSE role END';

            DB::statement("UPDATE users SET role_new = {$quotedRoleCase}");

            DB::statement("\n                UPDATE users\n                SET user_account_type = CASE\n                    WHEN role_new IN ('editor', 'deputy_editor', 'senior_reporter', 'journalist', 'producer') THEN 'publication_staff'\n                    WHEN role_new IN ('creator', 'agent') THEN 'creator'\n                    WHEN role_new IN ('super_admin', 'cs_manager', 'cs_agent', 'friends_family') THEN 'tidemark_staff'\n                    ELSE 'publication_staff'\n                END\n            ");

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });

            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('role_new', 'role');
            });

            DB::table('roles')->where('name', 'section_editor')->update(['name' => 'deputy_editor']);
            DB::table('roles')->where('name', 'senior_editor')->update(['name' => 'editor']);
            DB::table('roles')->where('name', 'admin')->update(['name' => 'super_admin']);
            DB::table('roles')->where('name', 'creator_manager')->update(['name' => 'agent']);
        });
    }

    public function down(): void
    {
        $oldRoles = ['journalist', 'section_editor', 'senior_editor', 'admin', 'creator', 'creator_manager'];

        DB::transaction(function () use ($oldRoles): void {
            Schema::table('users', function (Blueprint $table) use ($oldRoles) {
                $table->enum('role_old', $oldRoles)->nullable()->after('role');
            });

            DB::statement("\n                UPDATE users\n                SET role_old = CASE role\n                    WHEN 'journalist' THEN 'journalist'\n                    WHEN 'deputy_editor' THEN 'section_editor'\n                    WHEN 'editor' THEN 'senior_editor'\n                    WHEN 'super_admin' THEN 'admin'\n                    WHEN 'creator' THEN 'creator'\n                    WHEN 'agent' THEN 'creator_manager'\n                    ELSE 'journalist'\n                END\n            ");

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });

            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('role_old', 'role');
            });

            if (Schema::hasColumn('users', 'user_account_type')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropColumn('user_account_type');
                });
            }

            DB::table('roles')->where('name', 'deputy_editor')->update(['name' => 'section_editor']);
            DB::table('roles')->where('name', 'editor')->update(['name' => 'senior_editor']);
            DB::table('roles')->where('name', 'super_admin')->update(['name' => 'admin']);
            DB::table('roles')->where('name', 'agent')->update(['name' => 'creator_manager']);
        });
    }
};
