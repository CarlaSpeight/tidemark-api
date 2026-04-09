<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Comment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'article_id' => Article::factory(),
            'platform' => fake()->randomElement(['website', 'facebook', 'instagram', 'twitter', 'youtube', 'tiktok', 'linkedin', 'substack']),
            'original_text' => fake()->sentence(),
            'normalised_text' => fake()->sentence(),
            'commenter_platform_id' => fake()->numerify('########'),
            'commenter_display_name' => fake()->name(),
            'toxicity_score' => fake()->numberBetween(0, 100),
            'confidence_score' => fake()->numberBetween(50, 100),
            'status' => fake()->randomElement(['pending', 'approved', 'hidden', 'restored', 'confirmed_deleted', 'deleted']),
            'routing_decision' => fake()->randomElement(['approve', 'hide', 'delete', 'queue']),
            'is_personal_attack' => fake()->boolean(15),
        ];
    }

    public function toxic(): static
    {
        return $this->state(fn () => [
            'toxicity_score' => fake()->numberBetween(70, 100),
            'routing_decision' => fake()->randomElement(['hide', 'delete']),
            'status' => 'hidden',
        ]);
    }

    public function clean(): static
    {
        return $this->state(fn () => [
            'toxicity_score' => fake()->numberBetween(0, 30),
            'routing_decision' => 'approve',
            'status' => 'approved',
        ]);
    }
}
