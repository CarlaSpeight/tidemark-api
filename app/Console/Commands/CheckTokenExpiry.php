<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Notifications\SocialTokenExpiringNotification;
use App\Services\SocialConnectionService;
use Illuminate\Console\Command;

class CheckTokenExpiry extends Command
{
    protected $signature = 'social:check-token-expiry';

    protected $description = 'Refresh expiring social tokens. Flag and notify on failure.';

    public function handle(SocialConnectionService $service): int
    {
        // Bypass tenant scope — this is a system-level command
        SocialConnection::$bypassTenantScope = true;

        try {
            $expiring = SocialConnection::query()
                ->where('platform', '!=', 'substack')
                ->where('is_active', true)
                ->where('needs_reauth', false)
                ->whereNotNull('token_expires_at')
                ->where('token_expires_at', '<=', now()->addDays(7))
                ->get();

            $this->info("Found {$expiring->count()} connection(s) expiring within 7 days.");

            foreach ($expiring as $connection) {
                $refreshed = $service->refreshToken($connection);

                if ($refreshed) {
                    $this->line("  ✓ Refreshed {$connection->platform} (ID {$connection->id})");
                } else {
                    $connection->update(['needs_reauth' => true]);
                    $this->warn("  ✗ Failed {$connection->platform} (ID {$connection->id}) — needs_reauth set");

                    $this->notifyTenantAdmin($connection);
                }
            }
        } finally {
            SocialConnection::$bypassTenantScope = false;
        }

        return self::SUCCESS;
    }

    private function notifyTenantAdmin(SocialConnection $connection): void
    {
        $admin = $connection->tenant()
            ->first()
            ?->users()
            ->whereIn('role', ['editor', 'deputy_editor', 'super_admin'])
            ->where('is_active', true)
            ->first();

        if ($admin) {
            $admin->notify(new SocialTokenExpiringNotification($connection));
        }
    }
}
