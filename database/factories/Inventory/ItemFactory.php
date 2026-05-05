<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'sku' => 'ITM-'.$this->faker->unique()->numerify('######'),
            'name' => ucwords($this->faker->unique()->words(2, true)),
            'description' => $this->faker->sentence(),
            'category_id' => Category::factory(),
            'base_unit_id' => Unit::factory(),
            'default_supplier_id' => null,
            'storage_type' => $this->faker->randomElement(['dry', 'cold', 'frozen', 'ambient']),
            'is_consumable' => true,
            'expiry_tracked' => $this->faker->boolean(30),
            'reorder_level' => $this->faker->randomFloat(3, 5, 100),
            'min_threshold' => $this->faker->randomFloat(3, 1, 10),
            'weighted_avg_cost' => $this->faker->randomFloat(4, 1, 500),
            'is_active' => true,
        ];
    }
}
