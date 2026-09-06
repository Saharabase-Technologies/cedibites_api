<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\MenuTag;
use App\Support\Menu\ReceiptNames;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $this->createCategoriesAndItems($branch);
        }
    }

    private function createCategoriesAndItems(Branch $branch): void
    {
        $categories = [
            ['name' => 'Main delights', 'slug' => 'main-delights', 'display_order' => 1],
            ['name' => 'Meat bites', 'slug' => 'meat-bites', 'display_order' => 2],
            ['name' => 'Combos', 'slug' => 'combos', 'display_order' => 3],
            ['name' => 'Soft bites', 'slug' => 'soft-bites', 'display_order' => 4],
        ];

        foreach ($categories as $categoryData) {
            $category = MenuCategory::updateOrCreate(
                ['branch_id' => $branch->id, 'slug' => $categoryData['slug']],
                array_merge($categoryData, ['is_active' => true])
            );

            $this->createMenuItems($branch, $category);
        }
    }

    private function createMenuItems(Branch $branch, MenuCategory $category): void
    {
        $menuItems = match ($category->slug) {
            'main-delights' => [
                [
                    'name' => 'Jollof',
                    'description' => 'Smoky party jollof rice — served with free coleslaw',
                    'is_popular' => true,
                    'sizes' => [
                        ['name' => 'Plain', 'price' => 65.00],
                        ['name' => 'Assorted', 'price' => 85.00],
                        ['name' => 'Seafood', 'price' => 105.00],
                    ],
                ],
                [
                    'name' => 'Fried Rice',
                    'description' => 'Mixed vegetable fried rice — served with free coleslaw',
                    'is_popular' => true,
                    'sizes' => [
                        ['name' => 'Plain', 'price' => 60.00],
                        ['name' => 'Assorted', 'price' => 80.00],
                        ['name' => 'Seafood', 'price' => 100.00],
                    ],
                ],
                [
                    'name' => 'Noodles',
                    'description' => 'Noodles — served with free coleslaw',
                    'sizes' => [
                        ['name' => 'Assorted', 'price' => 80.00],
                        ['name' => 'Seafood', 'price' => 100.00],
                    ],
                ],
                [
                    'name' => 'Banku',
                    'description' => 'Fermented corn & cassava dough with grilled tilapia',
                    'sizes' => [
                        ['name' => 'Grilled Tilapia', 'price' => 110.00],
                    ],
                ],
            ],
            'meat-bites' => [
                [
                    'name' => 'Drumsticks',
                    'description' => 'Crispy chicken drumsticks',
                    'is_popular' => true,
                    'sizes' => [
                        ['name' => 'Special Crunch - 5 pieces', 'price' => 65.00],
                        ['name' => 'Special Crunch - 10 pieces', 'price' => 110.00],
                        ['name' => 'Juicy Fried - 5 pieces', 'price' => 65.00],
                        ['name' => 'Juicy Fried - 10 pieces', 'price' => 110.00],
                    ],
                ],
                [
                    'name' => 'Rotisserie Grilled',
                    'description' => 'Perfectly seasoned and roasted chicken',
                    'is_popular' => true,
                    'sizes' => [
                        ['name' => 'Full', 'price' => 170.00],
                        ['name' => 'Half Cut', 'price' => 90.00],
                    ],
                ],
            ],
            'combos' => [
                [
                    'name' => 'Fried Rice / Jollof + 3 pieces of Chicken',
                    'description' => '"For the street" — Choose Fried Rice or Jollof with 3 pieces of chicken',
                    'is_popular' => true,
                    'sizes' => [
                        ['name' => 'Fried Rice', 'price' => 90.00],
                        ['name' => 'Jollof', 'price' => 95.00],
                    ],
                ],
                [
                    'name' => 'Assorted Fried Rice / Jollof / Noodles + 3 pieces of Chicken',
                    'description' => '"For the street" — Assorted Fried Rice, Jollof, or Noodles with 3 pieces of chicken',
                    'is_popular' => true,
                    'sizes' => [
                        ['name' => 'Fried Rice', 'price' => 110.00],
                        ['name' => 'Jollof', 'price' => 115.00],
                        ['name' => 'Noodles', 'price' => 110.00],
                    ],
                ],
                [
                    'name' => 'Fried Rice / Jollof + 7 pieces of Chicken + Kɔkɔɔ',
                    'description' => '"Big budget meal" — Choose Fried Rice or Jollof with 7 pieces of chicken and kɔkɔɔ',
                    'sizes' => [
                        ['name' => 'Fried Rice', 'price' => 145.00],
                        ['name' => 'Jollof', 'price' => 150.00],
                    ],
                ],
                [
                    'name' => 'Assorted Fried Rice / Jollof / Noodles + 7 pieces of Chicken + Kɔkɔɔ',
                    'description' => '"Big budget meal" — Assorted Fried Rice, Jollof, or Noodles with 7 pieces of chicken and kɔkɔɔ',
                    'sizes' => [
                        ['name' => 'Fried Rice', 'price' => 165.00],
                        ['name' => 'Jollof', 'price' => 150.00],
                        ['name' => 'Noodles', 'price' => 165.00],
                    ],
                ],
                [
                    'name' => 'Assorted Fried Rice / Jollof / Noodles + Full Chicken + Kɔkɔɔ',
                    'description' => '"Big budget meal" — Assorted Fried Rice, Jollof, or Noodles with a full chicken and kɔkɔɔ',
                    'sizes' => [
                        ['name' => 'Fried Rice', 'price' => 250.00],
                        ['name' => 'Jollof', 'price' => 255.00],
                        ['name' => 'Noodles', 'price' => 250.00],
                    ],
                ],
            ],
            'soft-bites' => [
                [
                    'name' => 'Cedi Wraps',
                    'description' => 'Cedi wraps with your choice of filling',
                    'is_popular' => true,
                    'sizes' => [
                        ['name' => 'Chicken', 'price' => 60.00],
                        ['name' => 'Beef', 'price' => 60.00],
                        ['name' => 'Mix', 'price' => 70.00],
                    ],
                ],
            ],
            default => [],
        };

        $popularTag = MenuTag::query()->where('slug', 'popular')->first();

        foreach ($menuItems as $itemData) {
            $sizes = $itemData['sizes'] ?? [];
            $isPopular = (bool) ($itemData['is_popular'] ?? false);
            unset($itemData['sizes'], $itemData['is_popular']);

            $slug = Str::slug($itemData['name']);
            // Through ReceiptNames rather than the raw slug, so the four combos
            // resolve whether this file still calls them "3 Drums" or the
            // renamed "3 pieces of Chicken". Keying on the slug alone meant a
            // seed run on one side of that rename silently wrote no receipt
            // names at all.
            $displayNameMap = ReceiptNames::forItemName((string) $itemData['name']);

            $expectedOptionKeys = [];
            foreach ($sizes as $sizeData) {
                $expectedOptionKeys[] = Str::slug($sizeData['name']);
            }

            $item = MenuItem::updateOrCreate(
                ['branch_id' => $branch->id, 'slug' => $slug],
                array_merge($itemData, [
                    'category_id' => $category->id,
                    'is_available' => true,
                ])
            );

            if ($popularTag && $isPopular) {
                $item->tags()->syncWithoutDetaching([$popularTag->id]);
            }

            foreach ($sizes as $index => $sizeData) {
                $key = Str::slug($sizeData['name']);
                MenuItemOption::updateOrCreate(
                    ['menu_item_id' => $item->id, 'option_key' => $key],
                    [
                        'option_label' => $sizeData['name'],
                        'display_name' => $displayNameMap[$key] ?? null,
                        'price' => $sizeData['price'],
                        'display_order' => $index,
                        'is_available' => true,
                    ]
                );
            }

            // Ensure we don't leave old/incorrect options behind when prices/options change.
            if ($expectedOptionKeys !== []) {
                MenuItemOption::query()
                    ->where('menu_item_id', $item->id)
                    ->whereNotIn('option_key', $expectedOptionKeys)
                    ->update(['is_available' => false]);
            }
        }
    }

    /**
     * The receipt names, from the one place they are written down.
     *
     * They used to live in this file, which meant they only ever reached a
     * branch being seeded from scratch. Every menu already in production was
     * left with display_name null on nearly every option, and the receipt fell
     * back to the menu pill. See App\Support\Menu\ReceiptNames and
     * `php artisan menu:stamp-receipt-names`, which applies the same list to a
     * menu that is already live.
     *
     * @return array<string, array<string, string>> item slug → option key → display name
     */
    private static function displayNames(): array
    {
        return ReceiptNames::map();
    }
}
