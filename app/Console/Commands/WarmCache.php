<?php

namespace App\Console\Commands;

use App\Models\CreatorProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;
use App\Services\StatsCache;
use Illuminate\Console\Command;

class WarmCache extends Command
{
    protected $signature = 'cache:warm';

    protected $description = 'Pre-populate all dashboard caches for active tenants. Run before demos.';

    public function handle(ReportingService $service): int
    {
        $tenants = Tenant::where('is_active', true)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->info("Warming caches for: {$tenant->name}");

            // Senior dashboard (one per tenant)
            $seniorUser = User::where('tenant_id', $tenant->id)
                ->whereIn('role', ['senior_editor', 'admin'])
                ->where('is_active', true)
                ->first();

            if ($seniorUser) {
                StatsCache::seniorDashboard($tenant->id, fn () => $service->seniorDashboard($seniorUser));
                $this->line("  ✓ senior_dashboard:{$tenant->id}");
            }

            // Editor dashboards (per section)
            $sectionEditors = User::where('tenant_id', $tenant->id)
                ->where('role', 'section_editor')
                ->where('is_active', true)
                ->get();

            foreach ($sectionEditors as $editor) {
                StatsCache::editorDashboard($tenant->id, $editor->section, fn () => $service->editorDashboard($editor));
                $this->line("  ✓ editor_dashboard:{$tenant->id}:{$editor->section}");
            }

            // Topic heatmap (per tenant, via any editor user)
            $heatmapUser = $seniorUser ?? $sectionEditors->first();
            if ($heatmapUser) {
                StatsCache::topicHeatmap($tenant->id, fn () => $service->topicHeatmap($heatmapUser));
                $this->line("  ✓ topic_heatmap:{$tenant->id}");
            }

            // Journalist dashboards
            $journalists = User::where('tenant_id', $tenant->id)
                ->where('role', 'journalist')
                ->where('is_active', true)
                ->get();

            foreach ($journalists as $journalist) {
                StatsCache::journalistDashboard($journalist->id, fn () => $service->journalistDashboard($journalist));
                $this->line("  ✓ journalist_dashboard:{$journalist->id}");
            }

            // Creator dashboards
            $creators = User::where('tenant_id', $tenant->id)
                ->whereIn('role', ['creator', 'creator_manager'])
                ->where('is_active', true)
                ->get();

            foreach ($creators as $creator) {
                StatsCache::creatorDashboard($creator->id, fn () => $service->creatorDashboard($creator));
                $this->line("  ✓ creator_dashboard:{$creator->id}");
            }
        }

        $this->newLine();
        $this->info('All caches warmed successfully.');

        return self::SUCCESS;
    }
}
