<?php

namespace App\Services\SocialMedia;

class PlatformServiceFactory
{
    private static array $services = [
        'facebook' => FacebookService::class,
        'instagram' => InstagramService::class,
        'twitter' => TwitterService::class,
        'youtube' => YouTubeService::class,
        'tiktok' => TikTokService::class,
        'linkedin' => LinkedInService::class,
        'substack' => SubstackService::class,
    ];

    public static function make(string $platform): PlatformServiceInterface
    {
        $class = self::$services[$platform] ?? null;

        if (! $class) {
            throw new \InvalidArgumentException("Unknown platform: {$platform}");
        }

        return new $class();
    }

    public static function supports(string $platform): bool
    {
        return isset(self::$services[$platform]);
    }
}
