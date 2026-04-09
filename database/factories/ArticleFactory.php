<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'journalist_id' => User::factory(),
            'title' => fake()->sentence(),
            'url' => fake()->url(),
            'platform' => fake()->randomElement(['website', 'facebook', 'instagram', 'twitter', 'youtube', 'tiktok', 'linkedin', 'substack']),
            'platform_post_id' => fake()->numerify('post_########'),
            'published_at' => fake()->dateTimeBetween('-30 days'),
            'subject_entities' => fake()->randomElements(['politics', 'sport', 'tech', 'business', 'health', 'culture'], 2),
            'sensitivity_level' => fake()->randomElement(['low', 'medium', 'high']),
            'topic_category' => fake()->randomElement(['news', 'opinion', 'analysis', 'live', 'feature']),
            'comments_enabled' => true,
        ];
    }
}
