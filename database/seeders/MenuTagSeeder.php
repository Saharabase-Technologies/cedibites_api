<?php

namespace Database\Seeders;

use App\Models\MenuTag;
use Illuminate\Database\Seeder;

/**
 * Tags carry only what cannot be computed.
 *
 * `popular` and `new` used to live here, and duplicated the SmartCategory
 * resolvers that derive the same thing from real data — PopularResolver reads
 * 30 days of order frequency, NewArrivalsResolver reads timestamps. Both ran
 * customer-side at once, so a dish hand-tagged "Popular" that had not sold in a
 * month headed the Popular sort while being absent from the computed row.
 *
 * What is left is genuine attributes of the food: facts about a dish that no
 * amount of order history can infer. Those belong to a person.
 *
 * See RetireComputedMenuTagsSeeder for environments that already carry the old
 * two — a seeder that only adds cannot take anything away.
 */
class MenuTagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['slug' => 'spicy', 'name' => 'Spicy', 'display_order' => 1],
            ['slug' => 'vegetarian', 'name' => 'Vegetarian', 'display_order' => 2],
        ];

        foreach ($tags as $tag) {
            MenuTag::updateOrCreate(
                ['slug' => $tag['slug']],
                array_merge($tag, ['is_active' => true])
            );
        }
    }
}
