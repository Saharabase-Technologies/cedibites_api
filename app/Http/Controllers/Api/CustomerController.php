<?php

namespace App\Http\Controllers\Api;

use App\Enums\CustomerStatus;
use App\Events\CustomerSessionEvent;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
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
     * Export every customer contact (name + phone) across the whole table,
     * not just the current page. Phone numbers are normalised to +233XXXXXXXXX,
     * validated against the Ghana mobile format, and de-duplicated. Malformed,
     * null and junk numbers are dropped silently.
     *
     * Phones are sourced from the linked user (registered customers) and from
     * the latest order contact (guest customers), mirroring how the customer
     * list resolves a customer's phone.
     */
    public function exportContacts(Request $request): JsonResponse
    {
        $seen = [];
        $rows = [];

        // Strict Ghana mobile: +233 followed by 9 digits starting 2–9.
        $isValid = fn (string $phone): bool => (bool) preg_match('/^\+233[2-9]\d{8}$/', $phone);

        $add = function (?string $name, ?string $rawPhone) use (&$seen, &$rows, $isValid): void {
            if ($rawPhone === null || trim($rawPhone) === '') {
                return;
            }

            $normalized = PhoneHelper::normalize(trim($rawPhone));

            if (! $isValid($normalized) || isset($seen[$normalized])) {
                return;
            }

            $seen[$normalized] = true;
            $rows[] = [
                'name' => trim((string) $name) ?: 'Customer',
                'phone' => $normalized,
            ];
        };

        // Registered customers — phone lives on the linked user.
        Customer::with('user:id,name,phone')
            ->whereHas('user', fn ($q) => $q->whereNotNull('phone'))
            ->select('id', 'user_id')
            ->chunk(500, function ($customers) use ($add): void {
                foreach ($customers as $customer) {
                    $add($customer->user?->name, $customer->user?->phone);
                }
            });

        // Guest customers — phone lives on the order contact. Newest first so the
        // most recent name wins before de-duplication drops repeats.
        Order::whereNotNull('contact_phone')
            ->where('contact_phone', '!=', '')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->select('id', 'contact_name', 'contact_phone', 'created_at')
            ->chunk(1000, function ($orders) use ($add): void {
                foreach ($orders as $order) {
                    $add($order->contact_name, $order->contact_phone);
                }
            });

        activity('admin')
            ->causedBy($request->user())
            ->event('customer_contacts_exported')
            ->withProperties(['count' => count($rows)])
            ->log('Exported '.count($rows).' customer contacts');

        return response()->json(['data' => array_values($rows)]);
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
        $customer->load(['user', 'addresses', 'orders']);

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
