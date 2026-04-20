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

            // Tenant operations
            'tenants.view',
            'tenants.manage',

            // Billing and support operations
            'subscriptions.manage',
            'credits.manage',

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

        $publicationBasePermissions = [
            'comments.view',
            'articles.view',
            'reports.view',
            'prescore.use',
            'connections.view',
        ];

        $teamManagementPermissions = [
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
        ];

        // Publication roles
        $journalist = Role::firstOrCreate(['name' => 'journalist', 'guard_name' => 'sanctum']);
        $journalist->syncPermissions($publicationBasePermissions);

        $producer = Role::firstOrCreate(['name' => 'producer', 'guard_name' => 'sanctum']);
        $producer->syncPermissions($publicationBasePermissions);

        $seniorReporter = Role::firstOrCreate(['name' => 'senior_reporter', 'guard_name' => 'sanctum']);
        $seniorReporter->syncPermissions($publicationBasePermissions);

        $deputyEditor = Role::firstOrCreate(['name' => 'deputy_editor', 'guard_name' => 'sanctum']);
        $deputyEditor->syncPermissions([
            'comments.view', 'comments.moderate',
            'articles.view', 'articles.create',
            'engagement.view', 'engagement.approve',
            'connections.view', 'connections.manage',
            'reports.view', 'reports.export',
            'connections.manage',
            ...$teamManagementPermissions,
            'prescore.use',
        ]);

        $editor = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'sanctum']);
        $editor->syncPermissions([
            'comments.view', 'comments.moderate', 'comments.delete',
            'articles.view', 'articles.create', 'articles.update',
            'engagement.view', 'engagement.approve',
            'connections.view', 'connections.manage',
            'reports.view', 'reports.export',
            'users.view',
            'users.create',
            'users.update',
            'prescore.use',
        ]);

        // Creator roles
        $creator = Role::firstOrCreate(['name' => 'creator', 'guard_name' => 'sanctum']);
        $creator->syncPermissions([
            'comments.view', 'comments.moderate',
            'connections.view',
            'reports.view',
            'creator.manage_profile', 'creator.manage_shield',
        ]);

        $agent = Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'sanctum']);
        $agent->syncPermissions([
            'comments.view', 'comments.moderate', 'comments.delete',
            'connections.view', 'connections.manage',
            'engagement.view', 'engagement.approve',
            'reports.view', 'reports.export',
            'users.view', 'users.create', 'users.update',
            'settings.view', 'settings.update',
            'creator.manage_profile', 'creator.manage_shield',
        ]);

        // Internal staff roles
        $friendsFamily = Role::firstOrCreate(['name' => 'friends_family', 'guard_name' => 'sanctum']);
        $friendsFamily->syncPermissions(['comments.view', 'articles.view', 'reports.view']);

        $csAgent = Role::firstOrCreate(['name' => 'cs_agent', 'guard_name' => 'sanctum']);
        $csAgent->syncPermissions([
            'comments.view', 'articles.view', 'reports.view',
            'users.view', 'users.update',
            'tenants.view',
        ]);

        $csManager = Role::firstOrCreate(['name' => 'cs_manager', 'guard_name' => 'sanctum']);
        $csManager->syncPermissions([
            'comments.view', 'articles.view', 'reports.view', 'reports.export',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'tenants.view', 'tenants.manage',
            'subscriptions.manage', 'credits.manage',
            'settings.view',
        ]);

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'sanctum']);
        $superAdmin->syncPermissions(Permission::where('guard_name', 'sanctum')->pluck('name')->toArray());

        // Remove legacy roles after migration to keep permissions clean.
        Role::whereIn('name', ['section_editor', 'senior_editor', 'admin', 'creator_manager'])
            ->where('guard_name', 'sanctum')
            ->delete();
    }
}
