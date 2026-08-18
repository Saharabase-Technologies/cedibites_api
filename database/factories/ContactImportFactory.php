<?php

namespace Database\Factories;

use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactImportFactory extends Factory
{
    protected $model = ContactImport::class;

    public function definition(): array
    {
        return [
            'label' => fake()->words(3, true),
            'filename' => 'contacts.csv',
            'uploaded_by_user_id' => User::factory(),
            'total_rows' => 0,
            'imported_count' => 0,
            'duplicate_count' => 0,
            'invalid_count' => 0,
            'already_customer_count' => 0,
        ];
    }
}
