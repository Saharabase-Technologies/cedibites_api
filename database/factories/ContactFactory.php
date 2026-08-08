<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+233'.fake()->numerify('2########'),
            'source' => 'import',
            'converted_at' => null,
            'was_customer_before_import' => false,
        ];
    }

    /** Has since ordered — the list won them. */
    public function acquired(): static
    {
        return $this->state(fn () => [
            'converted_at' => now()->subDay(),
            'was_customer_before_import' => false,
        ]);
    }

    /** Was already ordering when the list was uploaded. */
    public function alreadyCustomer(): static
    {
        return $this->state(fn () => [
            'converted_at' => now()->subYear(),
            'was_customer_before_import' => true,
        ]);
    }
}
