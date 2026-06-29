<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a working IMS catalog (categories, suppliers, a warehouse location, and
 * items) so the Purchase Order + receiving flows are demoable end-to-end.
 * Idempotent: keyed by natural keys (slug / code / sku). Units come from
 * InventoryUnitsSeeder, which this seeder ensures has run.
 */
class InventoryCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Ensure base units exist.
        if (DB::table('inventory_units')->count() === 0) {
            $this->call(InventoryUnitsSeeder::class);
        }

        $unit = fn (string $code) => DB::table('inventory_units')->where('code', $code)->value('id');

        // ── Categories ───────────────────────────────────────────────────────
        $categories = [
            'Proteins' => 1, 'Grains & Starch' => 2, 'Vegetables' => 3, 'Oils & Fats' => 4,
            'Spices & Herbs' => 5, 'Beverages' => 6, 'Packaging' => 7, 'Condiments' => 8,
        ];
        foreach ($categories as $name => $sort) {
            DB::table('inventory_categories')->updateOrInsert(
                ['slug' => Str::slug($name)],
                ['parent_id' => null, 'name' => $name, 'sort_order' => $sort, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
        $cat = fn (string $name) => DB::table('inventory_categories')->where('slug', Str::slug($name))->value('id');

        // ── Suppliers ────────────────────────────────────────────────────────
        $suppliers = [
            ['code' => 'SUP-001', 'name' => 'Accra Meat Depot',       'contact_name' => 'Kwame Asante',     'phone' => '0244111222', 'email' => 'kwame@accrameat.gh',  'address' => 'Agbogbloshie, Accra',     'payment_terms_days' => 7],
            ['code' => 'SUP-002', 'name' => 'Tema Port Fresh Veg',    'contact_name' => 'Ama Owusu',        'phone' => '0208334455', 'email' => 'ama@temafresh.gh',    'address' => 'Tema Community 5',         'payment_terms_days' => 0],
            ['code' => 'SUP-003', 'name' => 'Golden Fry Oils Ltd',    'contact_name' => 'Emmanuel Boateng',  'phone' => '0302876543', 'email' => 'info@goldenfry.gh',   'address' => 'Spintex Rd, Accra',        'payment_terms_days' => 14],
            ['code' => 'SUP-004', 'name' => 'CoolDrinks GH Dist.',    'contact_name' => 'Kofi Mensah',       'phone' => '0277654321', 'email' => 'kofi@cooldrinks.gh',  'address' => 'Achimota, Accra',          'payment_terms_days' => 7],
            ['code' => 'SUP-005', 'name' => 'North Rice Growers Co.', 'contact_name' => 'Abdulai Ibrahim',   'phone' => '0244789012', 'email' => 'abdulai@northrice.gh','address' => 'Tamale, Northern Region',  'payment_terms_days' => 30],
            ['code' => 'SUP-MKT', 'name' => 'Market / Cash Purchase', 'contact_name' => null, 'phone' => null, 'email' => null, 'address' => null, 'payment_terms_days' => 0, 'notes' => 'Generic vendor for open-market and cash purchases. Capture the actual vendor on the receipt.'],
        ];
        foreach ($suppliers as $s) {
            DB::table('inventory_suppliers')->updateOrInsert(
                ['code' => $s['code']],
                array_merge(['contact_name' => null, 'phone' => null, 'email' => null, 'address' => null, 'notes' => null], $s, [
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]),
            );
        }
        $sup = fn (string $code) => DB::table('inventory_suppliers')->where('code', $code)->value('id');

        // ── Locations ────────────────────────────────────────────────────────
        // Mother kitchen (warehouse) + satellite kitchens mapped to branches when present.
        DB::table('inventory_locations')->updateOrInsert(
            ['code' => 'WH-001'],
            ['name' => 'Mother Kitchen — Spintex', 'type' => 'warehouse', 'branch_id' => null, 'address' => 'Spintex Rd, Accra, GH', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        );
        foreach (DB::table('branches')->orderBy('id')->limit(4)->get() as $i => $branch) {
            DB::table('inventory_locations')->updateOrInsert(
                ['code' => 'SK-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT)],
                ['name' => $branch->name.' Branch', 'type' => 'satellite', 'branch_id' => $branch->id, 'address' => $branch->address ?? null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        // ── Items ────────────────────────────────────────────────────────────
        $items = [
            ['sku' => 'ITM-000001', 'name' => 'Chicken Thighs (Bone-in)', 'cat' => 'Proteins',        'unit' => 'kg', 'sup' => 'SUP-001', 'storage' => 'cold',    'reorder' => 20, 'min' => 8,  'cost' => 42.50, 'expiry' => true],
            ['sku' => 'ITM-000002', 'name' => 'Chicken Breast (Boneless)','cat' => 'Proteins',        'unit' => 'kg', 'sup' => 'SUP-001', 'storage' => 'cold',    'reorder' => 15, 'min' => 5,  'cost' => 58.00, 'expiry' => true],
            ['sku' => 'ITM-000003', 'name' => 'Tilapia (Fresh)',          'cat' => 'Proteins',        'unit' => 'kg', 'sup' => 'SUP-001', 'storage' => 'cold',    'reorder' => 10, 'min' => 3,  'cost' => 35.00, 'expiry' => true],
            ['sku' => 'ITM-000004', 'name' => 'Basmati Rice',             'cat' => 'Grains & Starch', 'unit' => 'kg', 'sup' => 'SUP-005', 'storage' => 'dry',     'reorder' => 50, 'min' => 20, 'cost' => 8.80,  'expiry' => false],
            ['sku' => 'ITM-000005', 'name' => 'Jollof Rice Parboiled',    'cat' => 'Grains & Starch', 'unit' => 'kg', 'sup' => 'SUP-005', 'storage' => 'dry',     'reorder' => 80, 'min' => 30, 'cost' => 6.20,  'expiry' => false],
            ['sku' => 'ITM-000006', 'name' => 'Tomatoes (Fresh)',         'cat' => 'Vegetables',      'unit' => 'kg', 'sup' => 'SUP-002', 'storage' => 'ambient', 'reorder' => 15, 'min' => 5,  'cost' => 4.50,  'expiry' => true],
            ['sku' => 'ITM-000007', 'name' => 'Onions (Large)',           'cat' => 'Vegetables',      'unit' => 'kg', 'sup' => 'SUP-002', 'storage' => 'ambient', 'reorder' => 20, 'min' => 7,  'cost' => 3.80,  'expiry' => false],
            ['sku' => 'ITM-000009', 'name' => 'Palm Oil',                 'cat' => 'Oils & Fats',     'unit' => 'l',  'sup' => 'SUP-003', 'storage' => 'ambient', 'reorder' => 20, 'min' => 5,  'cost' => 18.00, 'expiry' => false],
            ['sku' => 'ITM-000010', 'name' => 'Soyabean Oil',             'cat' => 'Oils & Fats',     'unit' => 'l',  'sup' => 'SUP-003', 'storage' => 'ambient', 'reorder' => 30, 'min' => 10, 'cost' => 14.50, 'expiry' => false],
            ['sku' => 'ITM-000011', 'name' => 'Curry Powder',             'cat' => 'Spices & Herbs',  'unit' => 'kg', 'sup' => null,      'storage' => 'dry',     'reorder' => 3,  'min' => 1,  'cost' => 22.00, 'expiry' => false],
            ['sku' => 'ITM-000013', 'name' => 'Malta Guinness',           'cat' => 'Beverages',       'unit' => 'pc', 'sup' => 'SUP-004', 'storage' => 'ambient', 'reorder' => 10, 'min' => 3,  'cost' => 85.00, 'expiry' => true],
            ['sku' => 'ITM-000014', 'name' => 'Bottled Water (500mL)',    'cat' => 'Beverages',       'unit' => 'pc', 'sup' => 'SUP-004', 'storage' => 'ambient', 'reorder' => 15, 'min' => 5,  'cost' => 28.00, 'expiry' => true],
            ['sku' => 'ITM-000015', 'name' => 'Takeaway Boxes (Large)',   'cat' => 'Packaging',       'unit' => 'pc', 'sup' => null,      'storage' => 'dry',     'reorder' => 5,  'min' => 2,  'cost' => 95.00, 'expiry' => false],
            ['sku' => 'ITM-000016', 'name' => 'Scotch Bonnet Pepper',     'cat' => 'Vegetables',      'unit' => 'kg', 'sup' => 'SUP-002', 'storage' => 'ambient', 'reorder' => 3,  'min' => 1,  'cost' => 16.50, 'expiry' => true],
            ['sku' => 'ITM-000024', 'name' => 'Mayonnaise',               'cat' => 'Condiments',      'unit' => 'l',  'sup' => null,      'storage' => 'cold',    'reorder' => 6,  'min' => 2,  'cost' => 32.00, 'expiry' => true],
            ['sku' => 'ITM-000030', 'name' => 'Seasoning Cubes (Maggi)',  'cat' => 'Spices & Herbs',  'unit' => 'pc', 'sup' => null,      'storage' => 'dry',     'reorder' => 10, 'min' => 3,  'cost' => 0.35,  'expiry' => false],
            ['sku' => 'ITM-000032', 'name' => 'Whole Chicken',            'cat' => 'Proteins',        'unit' => 'pc', 'sup' => 'SUP-001', 'storage' => 'frozen',  'reorder' => 10, 'min' => 4,  'cost' => 95.00, 'expiry' => true],
        ];
        foreach ($items as $it) {
            DB::table('inventory_items')->updateOrInsert(
                ['sku' => $it['sku']],
                [
                    'name' => $it['name'],
                    'description' => null,
                    'category_id' => $cat($it['cat']),
                    'base_unit_id' => $unit($it['unit']),
                    'default_supplier_id' => $it['sup'] ? $sup($it['sup']) : null,
                    'storage_type' => $it['storage'],
                    'is_consumable' => true,
                    'expiry_tracked' => $it['expiry'],
                    'reorder_level' => $it['reorder'],
                    'min_threshold' => $it['min'],
                    'weighted_avg_cost' => $it['cost'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
