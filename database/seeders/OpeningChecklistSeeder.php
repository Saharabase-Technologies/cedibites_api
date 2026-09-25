<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The branch manager's daily opening checklist, as the client wrote it
 * (September 2026): staffing, stock, and facility readiness, then a final
 * go or no-go.
 *
 * The client's rule, printed at the foot of the paper version: "If it can
 * affect food availability, food safety, customer experience or the branch's
 * ability to sell, don't simply tick the box. Fix it or escalate it before
 * opening." That is the grace period: a problem may be admitted and the
 * branch opened, and head office hears about it at once.
 *
 * Four items are food safety and must pass. The branch cannot open with any
 * of them failing; only head office can open it anyway, and that is recorded
 * as unusual. Everything else can be admitted as a problem.
 *
 * The final go or no-go on the paper form is not asked again here. It is
 * worked out from the answers, and "ready to open" is the button itself.
 *
 * Inserts only what is missing, by key, so it never overwrites a change made
 * to the checklist after it was first loaded.
 */
class OpeningChecklistSeeder extends Seeder
{
    public const STAFF = 'Staffing and team readiness';

    public const STOCK = 'Stock levels and product availability';

    public const FACILITY = 'Facility and operational readiness';

    public function run(): void
    {
        $existing = DB::table('opening_checklist_items')->pluck('key')->all();
        $now = now();
        $rows = [];

        // The first migration seeds before the second one adds `show_if`; that
        // one then applies the rules itself (applyRules).
        $hasRules = Schema::hasColumn('opening_checklist_items', 'show_if');

        foreach (self::items() as $position => $item) {
            if (in_array($item['key'], $existing, true)) {
                continue;
            }

            $rows[] = [
                'key' => $item['key'],
                'section' => $item['section'],
                'group' => $item['group'] ?? null,
                'label' => $item['label'],
                'short' => $item['short'],
                'help' => $item['help'] ?? null,
                'kind' => $item['kind'] ?? 'check',
                'weight' => $item['weight'] ?? (($item['kind'] ?? 'check') === 'check' ? 'can_open' : 'record'),
                'allows_na' => $item['allows_na'] ?? false,
                'position' => ($position + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ] + ($hasRules ? ['show_if' => isset(self::rules()[$item['key']]) ? json_encode(self::rules()[$item['key']]) : null] : []);
        }

        if ($rows !== []) {
            DB::table('opening_checklist_items')->insert($rows);
        }
    }

    /**
     * Which lines are asked only after another answer. See
     * App\Services\Openings\Relevance for the two shapes a rule takes.
     *
     * Everyone reported: nothing to ask about cover or who is missing. Every
     * ingredient there: no low-stock list to make. No problem in a section: no
     * notes box for it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function rules(): array
    {
        $staffMissing = ['when' => 'staff_reported', 'is' => 'problem'];
        $stockShort = ['any_problem' => ['Core food stock', 'Drinks and packaging']];

        return [
            'staff_absent_late' => $staffMissing,
            'absences_covered' => $staffMissing,
            'staffing_notes' => ['any_problem' => [self::STAFF]],
            'critical_sufficient' => ['any_problem' => ['Core food stock']],
            'low_stock_identified' => $stockShort,
            'out_of_stock_reported' => $stockShort,
            'replenishment_requested' => $stockShort,
            'low_stock_notes' => $stockShort,
            'out_of_stock_notes' => $stockShort,
            'replenishment_notes' => $stockShort,
            'facility_notes' => ['any_problem' => [self::FACILITY]],
            'facility_actions' => ['any_problem' => [self::FACILITY]],
        ];
    }

    /**
     * Put the rules on a checklist already loaded, and ask the attendance
     * question before the numbers that depend on it. Safe to run again.
     */
    public static function applyRules(): void
    {
        foreach (self::rules() as $key => $rule) {
            DB::table('opening_checklist_items')->where('key', $key)->update(['show_if' => json_encode($rule)]);
        }

        $first = DB::table('opening_checklist_items')->where('section', self::STAFF)->min('position');
        DB::table('opening_checklist_items')->where('key', 'staff_reported')->update(['position' => max(0, (int) $first - 5)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function items(): array
    {
        $staff = self::STAFF;
        $stock = self::STOCK;
        $facility = self::FACILITY;

        $food = fn (string $key, string $label, string $short) => [
            'key' => $key, 'section' => $stock, 'group' => 'Core food stock',
            'label' => $label, 'short' => $short, 'help' => 'Enough for today?',
        ];

        return [
            // ── 1. Staffing and team readiness ─────────────────────────────
            ['key' => 'staff_reported', 'section' => $staff, 'group' => 'Attendance',
                'label' => 'All scheduled staff have reported for duty.', 'short' => 'staff attendance'],
            ['key' => 'staff_scheduled', 'section' => $staff, 'group' => 'Attendance', 'kind' => 'number',
                'label' => 'Staff scheduled', 'short' => 'staff scheduled'],
            ['key' => 'staff_present', 'section' => $staff, 'group' => 'Attendance', 'kind' => 'number',
                'label' => 'Staff present', 'short' => 'staff present'],
            ['key' => 'staff_absent_late', 'section' => $staff, 'group' => 'Attendance', 'kind' => 'number',
                'label' => 'Absent or late', 'short' => 'absent or late'],
            ['key' => 'attendance_recorded', 'section' => $staff, 'group' => 'Attendance',
                'label' => 'Attendance and arrival times recorded.', 'short' => 'attendance record'],
            ['key' => 'absences_covered', 'section' => $staff, 'group' => 'Attendance', 'allows_na' => true,
                'label' => 'Any absent or late staff identified and replacement arranged where necessary.', 'short' => 'cover for absent staff'],
            ['key' => 'enough_staff', 'section' => $staff, 'group' => 'Attendance',
                'label' => 'Branch has enough staff to operate all required stations.', 'short' => 'enough staff'],

            ['key' => 'uniforms', 'section' => $staff, 'group' => 'Grooming and hygiene',
                'label' => 'All staff are in clean, complete uniforms.', 'short' => 'uniforms'],
            ['key' => 'protective_wear', 'section' => $staff, 'group' => 'Grooming and hygiene',
                'label' => 'Hair restraints, caps and other required protective clothing are being used.', 'short' => 'caps and protective wear'],
            ['key' => 'personal_hygiene', 'section' => $staff, 'group' => 'Grooming and hygiene',
                'label' => 'Fingernails, personal hygiene and general appearance meet CediBites standards.', 'short' => 'personal hygiene'],
            ['key' => 'no_unwell_staff', 'section' => $staff, 'group' => 'Grooming and hygiene', 'weight' => 'must_pass',
                'label' => 'No visibly unwell staff member is handling food.', 'short' => 'unwell staff handling food'],

            ['key' => 'stations_assigned', 'section' => $staff, 'group' => 'Deployment',
                'label' => 'Staff have been assigned to their stations.', 'short' => 'station assignments'],
            ['key' => 'kitchen_roles', 'section' => $staff, 'group' => 'Deployment',
                'label' => 'Kitchen responsibilities are clearly allocated.', 'short' => 'kitchen roles'],
            ['key' => 'service_roles', 'section' => $staff, 'group' => 'Deployment',
                'label' => 'Service and cashier responsibilities are clearly allocated.', 'short' => 'cashier roles'],
            ['key' => 'cleaning_roles', 'section' => $staff, 'group' => 'Deployment',
                'label' => 'Cleaning responsibilities are assigned.', 'short' => 'cleaning roles'],
            ['key' => 'team_briefed', 'section' => $staff, 'group' => 'Deployment',
                'label' => "Team has been briefed on today's priorities, promotions and expected service standards.", 'short' => 'team briefing'],

            ['key' => 'staffing_notes', 'section' => $staff, 'group' => null, 'kind' => 'text',
                'label' => 'Staffing issues and action taken', 'short' => 'staffing notes'],

            // ── 2. Stock levels and product availability ───────────────────
            $food('rice', 'Rice', 'rice'),
            $food('chicken', 'Chicken', 'chicken'),
            $food('fish', 'Tilapia and fish', 'tilapia and fish'),
            $food('proteins', 'Assorted meat and proteins', 'assorted meat'),
            $food('banku', 'Banku ingredients and portions', 'banku'),
            $food('eggs', 'Eggs', 'eggs'),
            $food('vegetables', 'Vegetables', 'vegetables'),
            $food('cooking_oil', 'Cooking oil', 'cooking oil'),
            $food('sauces', 'Sauces, seasonings and spices', 'sauces and spices'),
            $food('other_ingredients', 'Other menu-specific ingredients', 'other ingredients'),

            ['key' => 'drinks', 'section' => $stock, 'group' => 'Drinks and packaging',
                'label' => 'Water and beverages adequately stocked.', 'short' => 'drinks'],
            ['key' => 'food_packs', 'section' => $stock, 'group' => 'Drinks and packaging',
                'label' => 'Takeaway bowls and food packs available.', 'short' => 'food packs'],
            ['key' => 'carrier_bags', 'section' => $stock, 'group' => 'Drinks and packaging',
                'label' => 'Carrier bags available.', 'short' => 'carrier bags'],
            ['key' => 'cutlery', 'section' => $stock, 'group' => 'Drinks and packaging',
                'label' => 'Cutlery available.', 'short' => 'cutlery'],
            ['key' => 'napkins', 'section' => $stock, 'group' => 'Drinks and packaging',
                'label' => 'Napkins and tissues available.', 'short' => 'napkins'],
            ['key' => 'cups_lids', 'section' => $stock, 'group' => 'Drinks and packaging', 'allows_na' => true,
                'label' => 'Cups, bottles and lids available where applicable.', 'short' => 'cups and lids'],

            ['key' => 'opening_vs_closing', 'section' => $stock, 'group' => 'Stock verification',
                'label' => "Opening stock checked against previous day's closing stock.", 'short' => 'opening stock check'],
            ['key' => 'critical_sufficient', 'section' => $stock, 'group' => 'Stock verification',
                'label' => 'All critical ingredients are sufficient for projected sales.', 'short' => 'critical ingredients'],
            ['key' => 'low_stock_identified', 'section' => $stock, 'group' => 'Stock verification',
                'label' => 'Low-stock items identified.', 'short' => 'low stock list'],
            ['key' => 'out_of_stock_reported', 'section' => $stock, 'group' => 'Stock verification',
                'label' => 'Out-of-stock items identified and reported.', 'short' => 'out of stock list'],
            ['key' => 'replenishment_requested', 'section' => $stock, 'group' => 'Stock verification', 'allows_na' => true,
                'label' => 'Replenishment or purchases requested where required.', 'short' => 'replenishment'],
            ['key' => 'no_spoiled_food', 'section' => $stock, 'group' => 'Stock verification', 'weight' => 'must_pass',
                'label' => 'No expired, spoiled or compromised food products in use.', 'short' => 'spoiled food in use'],
            ['key' => 'cold_storage', 'section' => $stock, 'group' => 'Stock verification',
                'label' => 'Chillers and freezers are holding stock at appropriate temperatures.', 'short' => 'chiller temperatures'],

            ['key' => 'low_stock_notes', 'section' => $stock, 'group' => null, 'kind' => 'text',
                'label' => 'Low stock', 'short' => 'low stock'],
            ['key' => 'out_of_stock_notes', 'section' => $stock, 'group' => null, 'kind' => 'text',
                'label' => 'Out of stock', 'short' => 'out of stock'],
            ['key' => 'replenishment_notes', 'section' => $stock, 'group' => null, 'kind' => 'text',
                'label' => 'Replenishment required', 'short' => 'replenishment required'],

            // ── 3. Facility and operational readiness ──────────────────────
            ['key' => 'kitchen_clean', 'section' => $facility, 'group' => 'Kitchen',
                'label' => 'Kitchen thoroughly cleaned and ready.', 'short' => 'kitchen cleaning'],
            ['key' => 'cooking_equipment', 'section' => $facility, 'group' => 'Kitchen',
                'label' => 'Cooking equipment switched on, tested and working.', 'short' => 'cooking equipment'],
            ['key' => 'gas', 'section' => $facility, 'group' => 'Kitchen',
                'label' => 'Gas supply checked and adequate.', 'short' => 'gas'],
            ['key' => 'fridges', 'section' => $facility, 'group' => 'Kitchen',
                'label' => 'Refrigerators working.', 'short' => 'fridges'],
            ['key' => 'freezers', 'section' => $facility, 'group' => 'Kitchen',
                'label' => 'Freezers working.', 'short' => 'freezers'],
            ['key' => 'prep_areas', 'section' => $facility, 'group' => 'Kitchen',
                'label' => 'Prep areas clean and sanitised.', 'short' => 'prep areas'],
            ['key' => 'handwashing', 'section' => $facility, 'group' => 'Kitchen', 'weight' => 'must_pass',
                'label' => 'Handwashing points have water and soap.', 'short' => 'handwashing'],
            ['key' => 'bins', 'section' => $facility, 'group' => 'Kitchen',
                'label' => 'Waste bins emptied and properly lined.', 'short' => 'bins'],
            ['key' => 'no_pests', 'section' => $facility, 'group' => 'Kitchen', 'weight' => 'must_pass',
                'label' => 'No pest activity or food-safety concern observed.', 'short' => 'pests or food safety'],

            ['key' => 'dining_area', 'section' => $facility, 'group' => 'Customer and service areas',
                'label' => 'Dining and service area clean.', 'short' => 'dining area'],
            ['key' => 'tables_chairs', 'section' => $facility, 'group' => 'Customer and service areas',
                'label' => 'Tables and chairs clean and properly arranged.', 'short' => 'tables and chairs'],
            ['key' => 'counter', 'section' => $facility, 'group' => 'Customer and service areas',
                'label' => 'Counter and cashier area clean and organised.', 'short' => 'counter'],
            ['key' => 'menu_displays', 'section' => $facility, 'group' => 'Customer and service areas',
                'label' => 'Menu displays and signage properly positioned.', 'short' => 'menu displays'],
            ['key' => 'pos_working', 'section' => $facility, 'group' => 'Customer and service areas',
                'label' => 'POS system working.', 'short' => 'POS'],
            ['key' => 'internet', 'section' => $facility, 'group' => 'Customer and service areas',
                'label' => 'Internet and network working.', 'short' => 'internet'],
            ['key' => 'cash_float', 'section' => $facility, 'group' => 'Customer and service areas',
                'label' => 'Cash and change available for opening.', 'short' => 'cash float'],
            ['key' => 'order_phones', 'section' => $facility, 'group' => 'Customer and service areas',
                'label' => 'Delivery and order phones charged and working.', 'short' => 'order phones'],

            ['key' => 'electricity', 'section' => $facility, 'group' => 'Utilities and building',
                'label' => 'Electricity available.', 'short' => 'electricity'],
            ['key' => 'backup_power', 'section' => $facility, 'group' => 'Utilities and building', 'allows_na' => true,
                'label' => 'Backup power system ready where applicable.', 'short' => 'backup power'],
            ['key' => 'water', 'section' => $facility, 'group' => 'Utilities and building',
                'label' => 'Water supply available.', 'short' => 'water'],
            ['key' => 'lighting', 'section' => $facility, 'group' => 'Utilities and building',
                'label' => 'Lighting working.', 'short' => 'lighting'],
            ['key' => 'cooling', 'section' => $facility, 'group' => 'Utilities and building',
                'label' => 'Air conditioning and fans working.', 'short' => 'fans and AC'],
            ['key' => 'washrooms', 'section' => $facility, 'group' => 'Utilities and building',
                'label' => 'Washrooms clean, stocked and working.', 'short' => 'washrooms'],
            ['key' => 'doors_locks', 'section' => $facility, 'group' => 'Utilities and building',
                'label' => 'Doors, locks and access points secure and working.', 'short' => 'doors and locks'],
            ['key' => 'fire_safety', 'section' => $facility, 'group' => 'Utilities and building',
                'label' => 'Fire extinguishers and essential safety equipment accessible.', 'short' => 'fire safety'],
            ['key' => 'storefront', 'section' => $facility, 'group' => 'Utilities and building',
                'label' => 'Exterior and storefront clean and presentable.', 'short' => 'storefront'],
            ['key' => 'no_maintenance_issue', 'section' => $facility, 'group' => 'Utilities and building',
                'label' => "No maintenance issue likely to disrupt today's operations.", 'short' => 'maintenance'],

            ['key' => 'facility_notes', 'section' => $facility, 'group' => null, 'kind' => 'text',
                'label' => 'Facility or maintenance issues', 'short' => 'facility notes'],
            ['key' => 'facility_actions', 'section' => $facility, 'group' => null, 'kind' => 'text',
                'label' => 'Action taken and person notified', 'short' => 'action taken'],
        ];
    }
}
