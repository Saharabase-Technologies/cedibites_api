<?php

namespace Database\Factories;

use App\Enums\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'name' => 'August Friday jollof',
            'message' => 'CediBites: Friday treat! 20% off all jollof today only. Order: cedibites.com/r/A7X9Kp',
            'segment' => CampaignSegment::All,
            'status' => CampaignStatus::Draft,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function sending(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Sending,
            'started_at' => now(),
        ]);
    }
}
