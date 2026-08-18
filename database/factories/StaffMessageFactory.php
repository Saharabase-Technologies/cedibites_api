<?php

namespace Database\Factories;

use App\Enums\StaffMessageKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StaffMessage>
 */
class StaffMessageFactory extends Factory
{
    protected $model = \App\Models\StaffMessage::class;

    public function definition(): array
    {
        return [
            'sender_user_id' => \App\Models\User::factory(),
            'kind' => StaffMessageKind::Notice->value,
            'subject' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'audience' => ['everyone' => true],
            'requires_acknowledgement' => false,
            'allow_custom_reply' => true,
            'quick_replies' => ['Got it', 'Understood'],
            // Sent by default. A draft is the exception, and tests that want one
            // are explicit about it via unsent().
            'sent_at' => now(),
        ];
    }

    public function caution(): static
    {
        return $this->state(fn () => [
            'kind' => StaffMessageKind::Caution->value,
            'requires_acknowledgement' => true,
        ]);
    }

    public function unsent(): static
    {
        return $this->state(fn () => ['sent_at' => null]);
    }

    /** Sent by a rule rather than a person. */
    public function automatic(): static
    {
        return $this->state(fn () => ['sender_user_id' => null]);
    }
}
