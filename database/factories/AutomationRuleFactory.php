<?php

namespace Database\Factories;

use App\Enums\AutomationEvent;
use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AutomationRuleFactory extends Factory
{
    protected $model = AutomationRule::class;

    public function definition(): array
    {
        return [
            'name' => 'Ask after a first order',
            'event' => AutomationEvent::FirstOrder->value,
            'event_config' => null,
            'audience_rules' => null,
            'message' => 'How was your first CediBites order?',
            'delay_minutes' => 180,
            // Live by default in tests: nearly every one is about what a rule
            // does when it is switched on, and the off case is worth stating
            // explicitly where it matters.
            'is_active' => true,
            'priority' => 100,
            'sample_rate' => 100,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
