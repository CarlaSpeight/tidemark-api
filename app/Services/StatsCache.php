<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class StatsCache
{
    public static function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        return Cache::remember($key, $ttlSeconds, $callback);
    }

    public static function journalistDashboard(int $userId, callable $callback): mixed
    {
        return static::remember("reports:journalist:{$userId}", 120, $callback);
    }

    public static function editorDashboard(string $tenantId, ?string $section, callable $callback): mixed
    {
        $key = "reports:editor:{$tenantId}:" . ($section ?? 'all');

        return static::remember($key, 900, $callback);
    }

    public static function seniorDashboard(string $tenantId, callable $callback): mixed
    {
        return static::remember("reports:senior:{$tenantId}", 900, $callback);
    }

    public static function creatorDashboard(int $userId, callable $callback): mixed
    {
        return static::remember("reports:creator:{$userId}", 300, $callback);
    }

    public static function topicHeatmap(string $tenantId, callable $callback): mixed
    {
        return static::remember("reports:topic_heatmap:{$tenantId}", 1800, $callback);
    }

    /**
     * Return all cache key patterns for warming/flushing.
     */
    public static function allPatterns(): array
    {
        return [
            'reports:journalist:',
            'reports:editor:',
            'reports:senior:',
            'reports:creator:',
            'reports:topic_heatmap:',
        ];
    }

    public static function flush(string $pattern): void
    {
        if (config('cache.default') === 'redis') {
            $redis = Cache::store('redis')->getStore()->getRedis();
            $prefix = Cache::store('redis')->getPrefix();
            $keys = $redis->keys($prefix . $pattern . '*');

            if (! empty($keys)) {
                $redis->del($keys);
            }
        } else {
            Cache::flush();
        }
    }
}
