<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'subscription_tier' => fake()->randomElement(['trial', 'starter', 'professional', 'enterprise']),
            'product_tier' => 'media',
            'is_active' => true,
        ];
    }

    public function creator(): static
    {
        return $this->state(fn () => [
            'subscription_tier' => fake()->randomElement(['creator_free', 'creator_pro', 'creator_studio', 'creator_agency']),
            'product_tier' => 'creator',
        ]);
    }
}
