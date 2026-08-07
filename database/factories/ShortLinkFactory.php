<?php

namespace Database\Factories;

use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShortLink>
 */
class ShortLinkFactory extends Factory
{
    protected $model = ShortLink::class;

    public function definition(): array
    {
        return [
            'token' => ShortLink::generateToken(),
            'label' => fake()->words(3, true),
            'target_url' => 'https://app.cedibites.com/menu',
            'created_by_user_id' => User::factory(),
            'click_count' => 0,
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
