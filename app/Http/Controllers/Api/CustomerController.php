<?php

namespace App\Http\Controllers\Api;

use App\Enums\CampaignSegment;
use App\Enums\CustomerStatus;
use App\Events\CustomerSessionEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Services\Campaigns\AudienceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::with(['user', 'addresses', 'orders' => fn ($q) => $q->latest()->limit(1)])
            ->withCount('orders')
            ->withSum(['orders as total_spend' => fn ($q) => $q->whereIn('status', ['completed', 'delivered'])], 'total_amount')
            ->when($request->is_guest !== null, fn ($query) => $query->where('is_guest', $request->boolean('is_guest')))
            ->when($request->status, fn ($query) => $query->where('status', $request->status))
            ->when($request->search, fn ($query) => $query->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$request->search}%")->orWhere('phone', 'like', "%{$request->search}%")))
            ->when($request->sort_by === 'orders', fn ($query) => $query->orderByDesc('orders_count'))
            ->when($request->sort_by === 'spend', fn ($query) => $query->orderByRaw('total_spend DESC NULLS LAST'))
            ->when(! in_array($request->sort_by, ['orders', 'spend']), fn ($query) => $query->latest())
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'from' => $customers->firstItem(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'to' => $customers->lastItem(),
                'total' => $customers->total(),
            ],
            'links' => [
                'first' => $customers->url(1),
                'last' => $customers->url($customers->lastPage()),
                'prev' => $customers->previousPageUrl(),
                'next' => $customers->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Export customer contacts (name + phone) for SMS marketing. Optionally
     * filtered to a behavioural segment so churned / at-risk / loyal customers
     * can be targeted differently. Phones are normalised to +233XXXXXXXXX,
     * validated against the Ghana mobile format, and de-duplicated.
     *
     * Segments (by ordering behaviour, keyed by phone so guests are included):
     *   all       — everyone with a valid contact (default)
     *   active    — last ordered ≤30 days ago
     *   at_risk   — last ordered 31–60 days ago
     *   churned   — last ordered >60 days ago
     *   loyal     — 2+ orders (repeat customers, any recency)
     *   one_time  — exactly 1 order
     */
    public function exportContacts(Request $request, AudienceResolver $audience): JsonResponse
    {
        // Delegated rather than implemented here. The campaign console sends to
        // the same six segments, and two implementations of "churned" would
        // drift the day one of them was tuned — the export listing one set of
        // people and the blast reaching another, both looking correct.
        $segment = CampaignSegment::tryFrom((string) $request->query('segment', 'all'))
            ?? CampaignSegment::All;

        $rows = $audience->resolve($segment);
        $segment = $segment->value;

        activity('admin')
            ->causedBy($request->user())
            ->event('customer_contacts_exported')
            ->withProperties(['count' => count($rows), 'segment' => $segment])
            ->log('Exported '.count($rows).' customer contacts ('.$segment.')');

        return response()->json(['data' => array_values($rows), 'segment' => $segment]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        try {
            $customer = Customer::create($request->validated());

            return response()->created(
                new CustomerResource($customer->load('user'))
            );
        } catch (\Exception $e) {
            return response()->server_error();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['user', 'addresses', 'orders', 'orderAliases']);

        return response()->success(new CustomerResource($customer));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        try {
            $customer->update($request->validated());

            return response()->success(
                new CustomerResource($customer->fresh('user'))
            );
        } catch (\Exception $e) {
            return response()->server_error();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        try {
            $customerName = $customer->user?->name ?? 'Guest Customer';

            activity('admin')
                ->causedBy($request->user())
                ->performedOn($customer)
                ->event('customer_deleted')
                ->withProperties(['customer_id' => $customer->id])
                ->log('Customer deleted: '.$customerName);

            $customer->delete();

            return response()->success(
                null,
                'Customer account deleted successfully.'
            );
        } catch (\Exception $e) {
            return response()->server_error();
        }
    }

    /**
     * Suspend a customer account.
     */
    public function suspend(Request $request, Customer $customer): JsonResponse
    {
        try {
            $customer->update(['status' => CustomerStatus::Suspended]);

            // Revoke all auth tokens so the customer is immediately logged out
            if ($customer->user) {
                CustomerSessionEvent::dispatch($customer->user);
                $customer->user->tokens()->delete();
            }

            activity('admin')
                ->causedBy($request->user())
                ->performedOn($customer)
                ->event('customer_suspended')
                ->withProperties(['customer_id' => $customer->id])
                ->log('Customer suspended: '.($customer->user?->name ?? $customer->guest_session_id));

            return response()->success(
                new CustomerResource($customer->fresh(['user', 'addresses'])),
                'Customer account suspended successfully.'
            );
        } catch (\Exception $e) {
            return response()->server_error();
        }
    }

    /**
     * Unsuspend a customer account.
     */
    public function unsuspend(Request $request, Customer $customer): JsonResponse
    {
        try {
            $customer->update(['status' => CustomerStatus::Active]);

            activity('admin')
                ->causedBy($request->user())
                ->performedOn($customer)
                ->event('customer_unsuspended')
                ->withProperties(['customer_id' => $customer->id])
                ->log('Customer unsuspended: '.($customer->user?->name ?? $customer->guest_session_id));

            return response()->success(
                new CustomerResource($customer->fresh(['user', 'addresses'])),
                'Customer account unsuspended successfully.'
            );
        } catch (\Exception $e) {
            return response()->server_error();
        }
    }

    /**
     * Force-logout a customer by revoking all tokens.
     */
    public function forceLogout(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->user) {
            CustomerSessionEvent::dispatch($customer->user);
            $customer->user->tokens()->delete();
        }

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($customer)
            ->event('customer_force_logout')
            ->withProperties(['customer_id' => $customer->id])
            ->log('Customer force-logout: '.($customer->user?->name ?? $customer->guest_session_id));

        return response()->success(null, 'Customer logged out successfully.');
    }

    /**
     * Get customer orders.
     */
    public function orders(Request $request, Customer $customer): JsonResponse
    {
        $query = $customer->orders()->with(['items.menuItemOption.menuItem', 'payments', 'branch']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'from' => $orders->firstItem(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'to' => $orders->lastItem(),
                'total' => $orders->total(),
            ],
            'links' => [
                'first' => $orders->url(1),
                'last' => $orders->url($orders->lastPage()),
                'prev' => $orders->previousPageUrl(),
                'next' => $orders->nextPageUrl(),
            ],
        ]);
    }
}
