<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryUnitsSeeder extends Seeder
{
    /**
     * Seed the system-managed SI units.
     *
     * These are pre-populated and should not be editable by operators.
     * Admin-managed units (Carton, Bottle, Bag, etc.) are added via the UI.
     */
    public function run(): void
    {
        $units = [
            // ── Mass ───────────────────────────────────────────────────────
            ['code' => 'kg',  'name' => 'Kilogram',   'symbol' => 'kg',  'dimension' => 'mass',   'is_base_unit' => true],
            ['code' => 'g',   'name' => 'Gram',        'symbol' => 'g',   'dimension' => 'mass',   'is_base_unit' => false],
            ['code' => 'mg',  'name' => 'Milligram',   'symbol' => 'mg',  'dimension' => 'mass',   'is_base_unit' => false],
            ['code' => 't',   'name' => 'Tonne',       'symbol' => 't',   'dimension' => 'mass',   'is_base_unit' => false],
            ['code' => 'lb',  'name' => 'Pound',       'symbol' => 'lb',  'dimension' => 'mass',   'is_base_unit' => false],
            ['code' => 'oz',  'name' => 'Ounce',       'symbol' => 'oz',  'dimension' => 'mass',   'is_base_unit' => false],

            // ── Volume ─────────────────────────────────────────────────────
            ['code' => 'l',   'name' => 'Litre',       'symbol' => 'L',   'dimension' => 'volume', 'is_base_unit' => true],
            ['code' => 'ml',  'name' => 'Millilitre',  'symbol' => 'mL',  'dimension' => 'volume', 'is_base_unit' => false],
            ['code' => 'cl',  'name' => 'Centilitre',  'symbol' => 'cL',  'dimension' => 'volume', 'is_base_unit' => false],

            // ── Count ──────────────────────────────────────────────────────
            // "Piece" is the base; cartons, bags, bottles, etc. are admin-added.
            ['code' => 'pc',  'name' => 'Piece',       'symbol' => 'pc',  'dimension' => 'count',  'is_base_unit' => true],

            // ── Length ─────────────────────────────────────────────────────
            ['code' => 'm',   'name' => 'Metre',       'symbol' => 'm',   'dimension' => 'length', 'is_base_unit' => true],
            ['code' => 'cm',  'name' => 'Centimetre',  'symbol' => 'cm',  'dimension' => 'length', 'is_base_unit' => false],
            ['code' => 'mm',  'name' => 'Millimetre',  'symbol' => 'mm',  'dimension' => 'length', 'is_base_unit' => false],
        ];

        $now = now();

        foreach ($units as $unit) {
            DB::table('inventory_units')->updateOrInsert(
                ['code' => $unit['code']],
                array_merge($unit, [
                    'is_active'  => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]),
            );
        }
    }
}
