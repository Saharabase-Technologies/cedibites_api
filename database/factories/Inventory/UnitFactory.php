<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        $units = [
            ['code' => 'kg', 'name' => 'Kilogram', 'symbol' => 'kg', 'dimension' => 'mass', 'is_base_unit' => true],
            ['code' => 'g', 'name' => 'Gram', 'symbol' => 'g', 'dimension' => 'mass', 'is_base_unit' => false],
            ['code' => 'l', 'name' => 'Litre', 'symbol' => 'L', 'dimension' => 'volume', 'is_base_unit' => true],
            ['code' => 'ml', 'name' => 'Millilitre', 'symbol' => 'mL', 'dimension' => 'volume', 'is_base_unit' => false],
            ['code' => 'pc', 'name' => 'Piece', 'symbol' => 'pc', 'dimension' => 'count', 'is_base_unit' => true],
        ];

        $pick = $this->faker->randomElement($units);

        return [
            'code' => $pick['code'].'-'.$this->faker->unique()->numerify('###'),
            'name' => $pick['name'],
            'symbol' => $pick['symbol'],
            'dimension' => $pick['dimension'],
            'is_base_unit' => $pick['is_base_unit'],
            'is_active' => true,
        ];
    }
}
