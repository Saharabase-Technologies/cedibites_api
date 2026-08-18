<?php

namespace Database\Factories;

use App\Enums\StaffMessageEvent;
use App\Enums\StaffMessageKind;
use App\Enums\StaffMessageTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StaffMessageRule>
 */
class StaffMessageRuleFactory extends Factory
{
    protected $model = \App\Models\StaffMessageRule::class;

    public function definition(): array
    {
        return [
            'name' => 'Stalled order — '.$this->faker->unique()->word(),
            'event' => StaffMessageEvent::OrderStalled->value,
            'conditions' => ['status' => 'received', 'minutes' => 15],
            'target' => ['types' => [StaffMessageTarget::Actor->value]],
            'kind' => StaffMessageKind::Caution->value,
            'subject' => 'Order {order_number} has not moved',
            'body_template' => 'Hi {first_name}, {order_number} has been sitting for {minutes} minutes.',
            'requires_acknowledgement' => true,
            'allow_custom_reply' => true,
            'quick_replies' => ['Moving it now'],
            'cooldown_minutes' => 120,
            'priority' => 100,
            // Off by default, matching the real thing. A test that wants a live
            // rule says so.
            'is_active' => false,
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
