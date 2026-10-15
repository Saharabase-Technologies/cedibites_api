<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmployeeStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\TakePayment;
use App\Services\Payments\TakePaymentRefused;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Take payment on an order that owes (D7): Pay later, and later the order
 * with items added and the split sale. One part at a time, by cash, card, a
 * branch code payment, or a MoMo prompt to any number.
 */
class OrderPaymentController extends Controller
{
    /** POST /pos/orders/{order}/payments: one part of what the order owes. */
    public function store(Request $request, Order $order, TakePayment $take): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'string', 'in:cash,card,branch_code,mobile_money'],
            // Cash: what the customer handed over. More than is owed comes back as change.
            'handed_over' => ['required_if:method,cash', 'nullable', 'numeric', 'min:0.01', 'max:99999'],
            'amount' => ['required_if:method,card,mobile_money', 'nullable', 'numeric', 'min:0.01', 'max:99999'],
            'incoming_payment_id' => ['required_if:method,branch_code', 'nullable', 'integer'],
            'momo_number' => ['required_if:method,mobile_money', 'nullable', 'string', 'regex:/^(0[0-9]{9}|\+?233[0-9]{9})$/'],
        ]);

        $employee = $this->cashier($request, $order);
        $change = null;

        try {
            $payment = match ($validated['method']) {
                'cash' => (function () use ($take, $order, $employee, $validated, &$change) {
                    ['payment' => $payment, 'change' => $change] = $take->cash($order, $employee, (float) $validated['handed_over']);

                    return $payment;
                })(),
                'card' => $take->card($order, $employee, (float) $validated['amount']),
                'branch_code' => $take->branchCode($order, $employee, (int) $validated['incoming_payment_id']),
                'mobile_money' => $take->momoPrompt($order, $employee, $validated['momo_number'], (float) $validated['amount']),
            };
        } catch (TakePaymentRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }

        return response()->json(['data' => [
            'payment_id' => $payment->id,
            'payment_status' => $payment->payment_status,
            'change' => $change,
            'order' => new OrderResource($order->fresh()),
        ]], 201);
    }

    /**
     * POST /pos/orders/{order}/payments/{payment}/check: where a part stands.
     *
     * Read from our own record by default, which Hubtel's callback updates, so
     * the till can ask every few seconds while a prompt waits. With
     * ask_hubtel, Hubtel's status check is asked too, for a callback that
     * never came. The cashier presses for that one.
     */
    public function check(Request $request, Order $order, Payment $payment, TakePayment $take): JsonResponse
    {
        if ($payment->order_id !== $order->id) {
            return response()->json(['message' => 'That payment is not on this order.'], 404);
        }

        $this->cashier($request, $order);

        if ($request->boolean('ask_hubtel')) {
            try {
                $payment = $take->checkPrompt($payment);
            } catch (\Throwable) {
                return response()->json(['message' => 'Hubtel did not answer. Try again in a moment.'], 502);
            }
        } else {
            $payment->refresh();
        }

        return response()->json(['data' => [
            'payment_id' => $payment->id,
            'payment_status' => $payment->payment_status,
            'order' => new OrderResource($order->fresh()),
        ]]);
    }

    /** The signed-in employee, if they may take money for this order's branch. */
    private function cashier(Request $request, Order $order): Employee
    {
        $user = $request->user();
        $employee = $user->employee;

        if (! $employee || $employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(response()->json(['message' => 'Employee record not found or inactive.'], 403));
        }

        // The same rule as placing an order: a company-wide role anywhere, a
        // manager at the branches they manage, everyone else at their own.
        $allowed = $user->isCompanyWide()
            || ($user->hasRole(Role::Manager) && $employee->managedBranches()->where('branches.id', $order->branch_id)->exists())
            || $employee->branches()->where('branches.id', $order->branch_id)->exists();

        if (! $allowed) {
            throw new HttpResponseException(response()->json(['message' => 'You do not work at the branch this order belongs to.'], 403));
        }

        return $employee;
    }
}
