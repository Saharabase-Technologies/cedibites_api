<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['warehouse', 'satellite']);

        return [
            'code' => strtoupper($type === 'warehouse' ? 'WH' : 'SK').'-'.$this->faker->unique()->numerify('###'),
            'name' => ($type === 'warehouse' ? 'Mother Kitchen ' : 'Satellite Kitchen ').$this->faker->city(),
            'type' => $type,
            'branch_id' => null,
            'address' => $this->faker->address(),
            'is_active' => true,
        ];
    }

    public function warehouse(): self
    {
        return $this->state(fn () => ['type' => 'warehouse']);
    }

    public function satellite(): self
    {
        return $this->state(fn () => ['type' => 'satellite']);
    }
}
