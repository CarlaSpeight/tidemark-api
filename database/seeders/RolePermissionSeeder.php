<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Comments
            'comments.view',
            'comments.moderate',
            'comments.delete',

            // Articles
            'articles.view',
            'articles.create',
            'articles.update',

            // Engagement
            'engagement.view',
            'engagement.approve',

            // Social Connections
            'connections.view',
            'connections.manage',

            // Reports
            'reports.view',
            'reports.export',

            // Users
            'users.view',
            'users.create',
            'users.update',
            'users.delete',

            // Settings
            'settings.view',
            'settings.update',

            // Audit
            'audit.view',

            // Prescore
            'prescore.use',

            // Creator
            'creator.manage_profile',
            'creator.manage_shield',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        // Media tier roles
        $journalist = Role::firstOrCreate(['name' => 'journalist', 'guard_name' => 'sanctum']);
        $journalist->syncPermissions(['comments.view', 'articles.view', 'reports.view', 'prescore.use']);

        $sectionEditor = Role::firstOrCreate(['name' => 'section_editor', 'guard_name' => 'sanctum']);
        $sectionEditor->syncPermissions([
            'comments.view', 'comments.moderate',
            'articles.view', 'articles.create',
            'engagement.view', 'engagement.approve',
            'connections.view',
            'reports.view', 'reports.export',
            'prescore.use',
        ]);

        $seniorEditor = Role::firstOrCreate(['name' => 'senior_editor', 'guard_name' => 'sanctum']);
        $seniorEditor->syncPermissions([
            'comments.view', 'comments.moderate', 'comments.delete',
            'articles.view', 'articles.create', 'articles.update',
            'engagement.view', 'engagement.approve',
            'connections.view', 'connections.manage',
            'reports.view', 'reports.export',
            'users.view',
            'prescore.use',
        ]);

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
        $admin->syncPermissions(Permission::where('guard_name', 'sanctum')->pluck('name')->toArray());

        // Creator tier roles
        $creator = Role::firstOrCreate(['name' => 'creator', 'guard_name' => 'sanctum']);
        $creator->syncPermissions([
            'comments.view', 'comments.moderate',
            'connections.view',
            'reports.view',
            'creator.manage_profile', 'creator.manage_shield',
        ]);

        $creatorManager = Role::firstOrCreate(['name' => 'creator_manager', 'guard_name' => 'sanctum']);
        $creatorManager->syncPermissions([
            'comments.view', 'comments.moderate', 'comments.delete',
            'connections.view', 'connections.manage',
            'engagement.view', 'engagement.approve',
            'reports.view', 'reports.export',
            'users.view', 'users.create', 'users.update',
            'settings.view', 'settings.update',
            'creator.manage_profile', 'creator.manage_shield',
        ]);
    }
}
