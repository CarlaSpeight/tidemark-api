<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Models\Tenant;
use App\Services\SocialMedia\PlatformServiceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollSocialComments extends Command
{
    protected $signature = 'social:poll-comments';

    protected $description = 'Poll all active social connections for new comments.';

    public function handle(): int
    {
        SocialConnection::$bypassTenantScope = true;

        try {
            $tenants = Tenant::where('is_active', true)->get();

            foreach ($tenants as $tenant) {
                $connections = SocialConnection::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('is_active', true)
                    ->where('needs_reauth', false)
                    ->where('platform', '!=', 'substack')
                    ->get();

                foreach ($connections as $connection) {
                    try {
                        $service = PlatformServiceFactory::make($connection->platform);
                        $service->pollNewComments($connection, $tenant);
                        $this->line("  ✓ {$tenant->name} / {$connection->platform}");
                    } catch (\Throwable $e) {
                        Log::error('Poll failed for connection', [
                            'connection_id' => $connection->id,
                            'platform' => $connection->platform,
                            'error' => $e->getMessage(),
                        ]);
                        $this->error("  ✗ {$tenant->name} / {$connection->platform}: {$e->getMessage()}");
                    }
                }
            }
        } finally {
            SocialConnection::$bypassTenantScope = false;
        }

        return self::SUCCESS;
    }
}
