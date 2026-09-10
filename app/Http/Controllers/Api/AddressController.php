<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The addresses a customer has saved.
 *
 * The `addresses` table has existed since February and nothing has ever read or
 * written it: no controller, no route, no rows on any environment. Checkout
 * remembered the last address in `localStorage` instead, which is one browser on
 * one device — so an address typed on a phone was gone the moment somebody
 * ordered from a laptop, and "Saved Addresses" sat on the account page as a
 * Coming Soon row above a table that was already migrated and waiting.
 *
 * **Every query is scoped to the caller's own customer row.** The route model
 * binding is deliberately not used: binding on the id alone would fetch
 * somebody else's address and hand it to the authorisation check afterwards,
 * and a 403 tells you that id exists. Scoping the lookup means a stranger's id
 * is simply not found.
 */
class AddressController extends Controller
{
    /** The signed-in customer, or null when the account has no customer row. */
    private function customerId(Request $request): ?int
    {
        return $request->user()?->customer?->id;
    }

    public function index(Request $request): JsonResponse
    {
        $customerId = $this->customerId($request);
        if ($customerId === null) {
            return response()->success([]);
        }

        $addresses = Address::query()
            ->where('customer_id', $customerId)
            // The default first, then most recently saved. Somebody scanning
            // this list at checkout wants the one they usually use at the top.
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->success(AddressResource::collection($addresses));
    }

    public function store(Request $request): JsonResponse
    {
        $customerId = $this->customerId($request);
        if ($customerId === null) {
            return response()->json(['message' => 'This account cannot save addresses.'], 422);
        }

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:40'],
            'full_address' => ['required', 'string', 'min:4', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        /**
         * The same address twice is not two addresses.
         *
         * Checkout will call this after every order, so without this a customer
         * who orders to the same door weekly would collect fifty identical
         * rows. Matched on the trimmed text, case-insensitively, because "17
         * Alhaji Sulley Road" and "17 alhaji sulley road" are one place.
         */
        $existing = Address::query()
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(TRIM(full_address)) = ?', [mb_strtolower(trim($validated['full_address']))])
            ->first();

        $address = DB::transaction(function () use ($validated, $customerId, $existing) {
            $wantsDefault = (bool) ($validated['is_default'] ?? false);

            // The first address a customer saves is their default whether they
            // asked for it or not. A list of one with nothing marked is a
            // decision nobody made.
            $isFirst = ! Address::query()->where('customer_id', $customerId)->exists();

            $attributes = [
                'label' => $validated['label'] ?? null,
                'full_address' => trim($validated['full_address']),
                'note' => $validated['note'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ];

            if ($existing) {
                // Keep whatever the customer had typed as a label or note if
                // this call did not bring one; an automatic save from checkout
                // must not wipe "Mum's house".
                $existing->fill(array_filter(
                    $attributes,
                    fn ($value, $key) => $value !== null || in_array($key, ['full_address'], true),
                    ARRAY_FILTER_USE_BOTH,
                ));
                $address = $existing;
            } else {
                $address = new Address($attributes);
                $address->customer_id = $customerId;
            }

            $address->is_default = $wantsDefault || $isFirst || $address->is_default;
            $address->save();

            if ($address->is_default) {
                $this->clearOtherDefaults($customerId, $address->id);
            }

            return $address;
        });

        return response()->json(
            ['data' => new AddressResource($address->fresh())],
            $existing ? 200 : 201,
        );
    }

    public function update(Request $request, int $address): JsonResponse
    {
        $customerId = $this->customerId($request);
        $row = $this->findOwn($customerId, $address);
        if (! $row) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        $validated = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'full_address' => ['sometimes', 'string', 'min:4', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($row, $validated, $customerId) {
            if (array_key_exists('full_address', $validated)) {
                $validated['full_address'] = trim($validated['full_address']);
            }

            $row->fill($validated)->save();

            if ($row->is_default) {
                $this->clearOtherDefaults($customerId, $row->id);
            }
        });

        return response()->success(new AddressResource($row->fresh()));
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $customerId = $this->customerId($request);
        $row = $this->findOwn($customerId, $address);
        if (! $row) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        $wasDefault = (bool) $row->is_default;
        $row->delete();

        /**
         * Deleting the default promotes the next one.
         *
         * Otherwise a customer with three addresses deletes the marked one and
         * is left with two, neither of which is default, and checkout has
         * nothing to pre-fill.
         */
        if ($wasDefault) {
            $next = Address::query()
                ->where('customer_id', $customerId)
                ->orderByDesc('id')
                ->first();

            $next?->forceFill(['is_default' => true])->save();
        }

        return response()->success(['deleted' => true]);
    }

    private function findOwn(?int $customerId, int $addressId): ?Address
    {
        if ($customerId === null) {
            return null;
        }

        return Address::query()
            ->where('customer_id', $customerId)
            ->whereKey($addressId)
            ->first();
    }

    /** Exactly one default per customer, always. */
    private function clearOtherDefaults(int $customerId, int $keepId): void
    {
        Address::query()
            ->where('customer_id', $customerId)
            ->whereKeyNot($keepId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
