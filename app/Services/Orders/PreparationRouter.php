<?php

namespace App\Services\Orders;

use App\Models\MenuItem;
use Illuminate\Support\Collection;

/**
 * Decides whether a new order needs to go through the kitchen at all.
 *
 * An order of two bottled drinks has nothing to prepare, but it still walked
 * the full New → Accepted → Cooking → Ready path, so somebody had to
 * acknowledge and "cook" a Coke. Worse, those tickets sat on the board
 * alongside the ones that genuinely needed a pan, and the board is meant to
 * show what is outstanding.
 *
 * Three rules, and the reasoning matters more than the code:
 *
 *  1. **One line that needs cooking keeps the whole ticket on the board.** A
 *     burger with a Coke is still a burger. The alternative — splitting it into
 *     a kitchen ticket and a counter ticket — would break the order's identity,
 *     its payment, and its receipt, to save the kitchen from reading one line
 *     it can ignore.
 *
 *  2. **Where a no-prep order lands depends on how it was ordered.** At the
 *     till, the cashier hands the drink over while taking the money, so the
 *     transaction is the handover and there is no later step to record: it is
 *     complete. Ordered online, the same two drinks still have to be picked and
 *     given to a rider, so it lands in Ready with the Complete button waiting.
 *     Nothing is ever marked done without a person doing it.
 *
 *  3. **A sale rung up at the till opens already accepted.** Accepting an order
 *     means a person has taken responsibility for it, and at the till that
 *     already happened: the cashier took the money. Opening it in New asked
 *     that same cashier to walk to the board and acknowledge their own sale,
 *     which 355 of 359 of them duly did, a median of 45 seconds later. The
 *     click recorded nothing the order row did not already hold, because
 *     `assigned_employee_id` is written from the till session at creation.
 *     Online and WhatsApp still open in New, where somebody genuinely does
 *     have to accept.
 */
final class PreparationRouter
{
    /**
     * Sources where the customer is standing at the counter, so handing the
     * item over is part of the same transaction rather than a later step.
     */
    private const HANDED_OVER_AT_THE_TILL = ['pos', 'manual_entry'];

    /**
     * The status a brand-new order should be created in.
     *
     * Deliberately decided *before* the order row is written rather than
     * corrected afterwards. Creating it as `received` and updating it a moment
     * later would fire the observer twice, and the board would flash a new
     * ticket that then vanished on its own.
     *
     * @param  Collection<int, MenuItem>  $menuItems  the distinct menu items on the order
     */
    public function initialStatus(Collection $menuItems, string $orderSource): string
    {
        // Where it was taken decides what "waiting" means. At the till the
        // sale is already somebody's, so there is nobody left to accept it.
        $openingStatus = in_array($orderSource, self::HANDED_OVER_AT_THE_TILL, true)
            ? 'accepted'
            : 'received';

        // No idea what is on it — treat it as normal work.
        if ($menuItems->isEmpty()) {
            return $openingStatus;
        }

        $this->ensureCategoriesLoaded($menuItems);

        foreach ($menuItems as $menuItem) {
            if ($menuItem->requiresPreparation()) {
                return $openingStatus;
            }
        }

        return in_array($orderSource, self::HANDED_OVER_AT_THE_TILL, true)
            ? 'completed'
            : 'ready';
    }

    /**
     * True when the order never needed the kitchen. Useful for callers that
     * want to skip kitchen-facing side effects, not just the status.
     *
     * Both New and Accepted are kitchen work that has not started yet, so
     * neither counts as skipping it. Testing against `received` alone was
     * correct only while that was the sole opening status; a till sale now
     * opens in Accepted and still needs a pan.
     */
    public function skipsKitchen(Collection $menuItems, string $orderSource): bool
    {
        return ! in_array(
            $this->initialStatus($menuItems, $orderSource),
            ['received', 'accepted'],
            true,
        );
    }

    /**
     * Make sure every item can answer for its category, in one query.
     *
     * Not `loadMissing()`: that lives on Eloquent's collection, and this method
     * accepts the base `Support\Collection` so a caller can hand over a plain
     * `collect([$item])` — which is exactly what a test does. Resolving the
     * categories by hand keeps the signature honest and still costs one query
     * rather than one per item.
     */
    private function ensureCategoriesLoaded(Collection $menuItems): void
    {
        $missing = $menuItems->filter(
            fn (MenuItem $menuItem) => ! $menuItem->relationLoaded('category')
        );

        if ($missing->isEmpty()) {
            return;
        }

        $categories = \App\Models\MenuCategory::whereIn(
            'id',
            $missing->pluck('category_id')->filter()->unique()->all()
        )->get()->keyBy('id');

        foreach ($missing as $menuItem) {
            $menuItem->setRelation('category', $categories->get($menuItem->category_id));
        }
    }
}
