<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PromoOfferRequest;
use App\Http\Requests\ResolvePromoRequest;
use App\Http\Requests\StorePromoRequest;
use App\Http\Requests\UpdatePromoRequest;
use App\Http\Resources\PromoResource;
use App\Models\Promo;
use App\Services\PromoResolutionService;
use App\Support\Promos\PromoCodeRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

class PromoController extends Controller
{
    public function __construct(
        protected PromoResolutionService $promoResolution
    ) {}

    /**
     * List all promos.
     */
    public function index(): JsonResponse
    {
        $promos = Promo::query()
            ->with(['branches', 'menuItems'])
            ->withCount(['orders as times_used' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->orderBy('name')
            ->get();

        return response()->success(PromoResource::collection($promos));
    }

    /**
     * Get a single promo.
     */
    public function show(Promo $promo): JsonResponse
    {
        return response()->success(new PromoResource($promo));
    }

    /**
     * Create a promo.
     */
    public function store(StorePromoRequest $request): JsonResponse
    {
        $data = $request->validated();
        $branchIds = $data['branch_ids'] ?? [];
        $itemIds = $data['item_ids'] ?? [];
        $data['is_active'] = $data['is_active'] ?? true;
        unset($data['branch_ids'], $data['item_ids']);

        $promo = Promo::create($data);
        $promo->branches()->sync($branchIds);
        $promo->menuItems()->sync($itemIds);

        return response()->created(new PromoResource($promo->load(['branches', 'menuItems'])));
    }

    /**
     * Update a promo.
     */
    public function update(UpdatePromoRequest $request, Promo $promo): JsonResponse
    {
        $data = $request->validated();
        $validated = $request->validated();
        $branchIds = $data['branch_ids'] ?? null;
        $itemIds = $data['item_ids'] ?? null;
        unset($data['branch_ids'], $data['item_ids']);

        if (array_key_exists('branch_ids', $validated)) {
            $promo->branches()->sync($branchIds ?? []);
        }
        if (array_key_exists('item_ids', $validated)) {
            $promo->menuItems()->sync($itemIds ?? []);
        }

        $promo->update($data);

        return response()->success(new PromoResource($promo->fresh(['branches', 'menuItems'])));
    }

    /**
     * Delete a promo.
     */
    public function destroy(Promo $promo): JsonResponse
    {
        $promo->delete();

        return response()->deleted();
    }

    /**
     * The best promo that applies by itself.
     *
     * Kept for tills and browsers still running the build before codes. It
     * never returns a promo that has a code, since nobody typed one.
     */
    public function resolve(ResolvePromoRequest $request): JsonResponse
    {
        $subtotal = (float) ($request->subtotal ?? 0);
        $promo = $this->promoResolution->resolve(
            $request->item_ids,
            (string) $request->branch_id,
            $subtotal
        );

        if (! $promo) {
            return response()->success(null);
        }

        return response()->success([
            ...(new PromoResource($promo))->resolve($request),
            'discount' => $this->promoResolution->calculateDiscount($promo, $subtotal),
        ]);
    }

    /**
     * What comes off this basket, with or without a code.
     *
     * The checkout and the till both ask here, and both ask again when the
     * order is placed, so the figure a customer is shown and the figure they
     * are charged come from the same rule.
     *
     * Guessing is limited by misses, not by requests. A till re-checks the same
     * good code every time a dish is added, and that must never lock it out; a
     * run of codes that do not exist is what somebody guessing looks like.
     */
    public function offer(PromoOfferRequest $request): JsonResponse
    {
        $missKey = 'promo-code-miss:'.$request->ip();

        if ($request->filled('code') && RateLimiter::tooManyAttempts($missKey, 10)) {
            return response()->json([
                'code' => 'promo_code_refused',
                'reason' => 'too_many',
                'message' => 'Too many codes tried. Wait a minute and try again.',
            ], 429);
        }

        try {
            $offer = $this->promoResolution->bestOffer(
                (string) $request->branch_id,
                (float) $request->subtotal,
                $request->lines,
                $request->code,
                $request->phone,
            );
        } catch (PromoCodeRefused $refused) {
            if ($refused->reason === 'not_found') {
                RateLimiter::hit($missKey, 60);
            }

            return response()->json([
                'code' => 'promo_code_refused',
                'reason' => $refused->reason,
                'message' => $refused->getMessage(),
            ], 422);
        }

        return response()->success([
            'promo' => $offer->promo ? (new PromoResource($offer->promo))->resolve($request) : null,
            'discount' => $offer->discount,
            'code_beaten' => $offer->codeBeaten,
        ]);
    }
}
