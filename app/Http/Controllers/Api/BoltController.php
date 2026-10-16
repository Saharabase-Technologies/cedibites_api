<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItemOption;
use App\Services\Bolt\BoltBoard;
use App\Services\Bolt\BoltSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The menu's Bolt page: Bolt's price and promo on each dish, and what Bolt
 * keeps of an order.
 *
 * A Bolt price changes when Bolt's does, so it saves at once. It does not wait
 * for the New prices button, which is for our own counter prices.
 *
 * Until Bolt's own feed is connected, the till charges a Bolt order these
 * prices. Every write answers with the whole board.
 */
class BoltController extends Controller
{
    public function __construct(
        private BoltBoard $board,
        private BoltSettings $settings,
    ) {}

    public function index(): JsonResponse
    {
        return response()->success($this->board->build());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'commission_base' => ['required', 'string', Rule::in(BoltSettings::BASES)],
        ]);

        $before = $this->settings->toArray();
        $this->settings->update((float) $validated['commission_percent'], $validated['commission_base']);

        activity()
            ->causedBy($request->user())
            ->withProperties(['before' => $before, 'after' => $this->settings->toArray()])
            ->log('Bolt commission set to '.$this->settings->commissionPercent().'% of the '
                .($this->settings->commissionBase() === BoltSettings::BASE_LISTED ? 'listed price' : 'price paid'));

        return $this->index();
    }

    /** Set one option's Bolt price and promo. A null price takes it off Bolt. */
    public function updateOption(Request $request, MenuItemOption $option): JsonResponse
    {
        $validated = $request->validate([
            'bolt_price' => ['present', 'nullable', 'numeric', 'min:0.01', 'max:99999'],
            'bolt_promo_percent' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $price = $validated['bolt_price'] !== null ? round((float) $validated['bolt_price'], 2) : null;
        // A promo on a dish that is not on Bolt means nothing, and 0% is no promo.
        $promo = $price !== null && (float) ($validated['bolt_promo_percent'] ?? 0) > 0
            ? round((float) $validated['bolt_promo_percent'], 2)
            : null;

        $before = [
            'bolt_price' => $option->bolt_price !== null ? (float) $option->bolt_price : null,
            'bolt_promo_percent' => $option->bolt_promo_percent !== null ? (float) $option->bolt_promo_percent : null,
        ];

        $option->forceFill(['bolt_price' => $price, 'bolt_promo_percent' => $promo])->save();

        $name = $option->display_name ?: $option->menuItem?->name ?? "Option {$option->id}";

        activity()
            ->causedBy($request->user())
            ->performedOn($option)
            ->withProperties(['before' => $before, 'after' => ['bolt_price' => $price, 'bolt_promo_percent' => $promo]])
            ->log($price === null
                ? "{$name} taken off Bolt"
                : "{$name} on Bolt at GHS ".number_format($price, 2).($promo ? " with {$promo}% promo" : ''));

        return $this->index();
    }
}
