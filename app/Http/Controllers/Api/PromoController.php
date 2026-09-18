<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PromoOfferRequest;
use App\Http\Requests\ResolvePromoRequest;
use App\Http\Requests\StorePromoRequest;
use App\Http\Requests\UpdatePromoRequest;
use App\Http\Resources\PromoResource;
use App\Models\Promo;
use App\Models\PromoCode;
use App\Services\PromoResolutionService;
use App\Support\Promos\PromoCodeRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class PromoController extends Controller
{
    /**
     * What the admin list shows beside each promo: orders it is on, and for
     * one-off codes, how many were made and how many are spent. Cancelled
     * orders aside, because a cancelled order gives its use back.
     *
     * @return array<int|string, mixed>
     */
    private function counts(): array
    {
        $live = fn ($q) => $q->where('status', '!=', 'cancelled');

        return [
            'orders as times_used' => $live,
            'codes as codes_count',
            'codes as codes_used' => fn ($q) => $q->whereHas('orders', $live),
        ];
    }

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
            ->withCount($this->counts())
            ->orderBy('name')
            ->get();

        return response()->success(PromoResource::collection($promos));
    }

    /**
     * Get a single promo.
     */
    public function show(Promo $promo): JsonResponse
    {
        $promo->loadCount($this->counts());

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

    // ── One-off codes ───────────────────────────────────────────────────────

    /** The most codes one batch makes. */
    private const BATCH_MAX = 1000;

    /** The most codes one promo holds. */
    private const PROMO_MAX = 20000;

    /**
     * Every one-off code on a promo, and the order that used each one.
     *
     * Whole, not paged: the screen filters and pages it, and downloads the
     * same list as a spreadsheet to hand out.
     */
    public function codes(Promo $promo): JsonResponse
    {
        $codes = $promo->codes()
            ->with(['orders' => fn ($q) => $q->where('status', '!=', 'cancelled')
                ->select(['id', 'order_number', 'promo_code_id', 'created_at', 'contact_phone'])])
            ->orderBy('id')
            ->get()
            ->map(fn (PromoCode $c) => $this->codeRow($c));

        return response()->success($codes);
    }

    /**
     * Make a batch of one-off codes.
     *
     * Only for a promo given out as one-off codes. A prefix makes the batch
     * easy to tell apart on a flyer or in a list: JOLLOF-K7Q2MX. Without one a
     * code is eight characters.
     */
    public function generateCodes(Request $request, Promo $promo): JsonResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.self::BATCH_MAX],
            'prefix' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{1,12}$/'],
            'batch' => ['nullable', 'string', 'max:60'],
        ], [
            'prefix.regex' => 'A prefix is up to 12 letters or numbers.',
            'count.max' => 'One batch is at most '.self::BATCH_MAX.' codes.',
        ]);

        if ($promo->redemption !== Promo::SINGLE_USE) {
            return response()->json(['message' => 'Only a promo given out as one-off codes takes a batch of them.'], 422);
        }

        $count = (int) $data['count'];
        if ($promo->codes()->count() + $count > self::PROMO_MAX) {
            return response()->json(['message' => 'A promo holds at most '.number_format(self::PROMO_MAX).' codes.'], 422);
        }

        $shared = Promo::query()->where('redemption', Promo::SHARED_CODE)
            ->pluck('code')->map(fn ($c) => PromoCode::lookupKey($c))->flip();

        $made = DB::transaction(function () use ($promo, $data, $count, $request, $shared) {
            $fresh = [];
            // Draw until the batch is full of codes nobody holds. With 31
            // characters in six places a clash is rare; the loop is for the day
            // one happens.
            while (count($fresh) < $count) {
                $candidates = [];
                while (count($candidates) < $count - count($fresh)) {
                    $code = PromoCode::makeCode($data['prefix'] ?? null);
                    $candidates[PromoCode::lookupKey($code)] = $code;
                }

                $held = PromoCode::query()->whereIn('lookup', array_keys($candidates))->pluck('lookup')->flip();

                foreach ($candidates as $lookup => $code) {
                    if (! isset($fresh[$lookup]) && ! $held->has($lookup) && ! $shared->has($lookup)) {
                        $fresh[$lookup] = $code;
                    }
                }
            }

            $now = now();
            $rows = [];
            foreach ($fresh as $lookup => $code) {
                $rows[] = [
                    'promo_id' => $promo->id,
                    'code' => $code,
                    'lookup' => (string) $lookup,
                    'batch' => $data['batch'] ?? null,
                    'created_by' => $request->user()?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                PromoCode::insert($chunk);
            }

            return PromoCode::query()->whereIn('lookup', array_map('strval', array_keys($fresh)))->orderBy('id')->get();
        });

        activity('admin')
            ->performedOn($promo)
            ->causedBy($request->user())
            ->withProperties(['count' => $count, 'batch' => $data['batch'] ?? null, 'prefix' => $data['prefix'] ?? null])
            ->log('made one-off promo codes');

        return response()->created(
            $made->map(fn (PromoCode $c) => $this->codeRow($c->setRelation('orders', collect())))->values()
        );
    }

    /**
     * Take back a code nobody has used. A used one stays: the order names it.
     */
    public function destroyCode(Promo $promo, PromoCode $promoCode): JsonResponse
    {
        if ((int) $promoCode->promo_id !== (int) $promo->id) {
            abort(404);
        }

        $order = $promoCode->orders()->where('status', '!=', 'cancelled')->first();
        if ($order) {
            return response()->json([
                'message' => "{$promoCode->code} was used on order {$order->order_number}, so it stays on record.",
            ], 422);
        }

        $promoCode->delete();

        return response()->deleted();
    }

    /**
     * @return array<string, mixed>
     */
    private function codeRow(PromoCode $code): array
    {
        $order = $code->orders->first();

        return [
            'id' => (string) $code->id,
            'code' => $code->code,
            'batch' => $code->batch,
            'createdAt' => $code->created_at?->toIso8601String(),
            'used' => $order !== null,
            'orderNumber' => $order?->order_number,
            'usedAt' => $order?->created_at?->toIso8601String(),
            'usedBy' => $order?->contact_phone,
        ];
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
            // As it is written, so a screen shows JOLLOF-K7Q2MX however it was typed.
            'applied_code' => $offer->appliedCode,
        ]);
    }
}
