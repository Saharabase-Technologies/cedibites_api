<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * The things a rule can watch. Every case here is computable from data already
 * recorded — `order_status_history` stamps `changed_at` and `changed_by_id` on
 * every transition, which is the only reason the timing rules are possible at
 * all.
 *
 * Nothing goes in this enum that would need new instrumentation to answer. A rule
 * that cannot be dry-run against history is a rule nobody can be asked to approve.
 */
enum StaffMessageEvent: string
{
    use HasEnumHelpers;

    /**
     * An order has sat in one status longer than it should. Parameterised by
     * status, so "received, not moved in 15 minutes", "ready, not collected in
     * 20" and "out for delivery over an hour" are three rules of one type rather
     * than three code paths.
     */
    case OrderStalled = 'order_stalled';

    /**
     * The contact phone on an order is not a usable Ghana number, or is
     * well-formed junk — one digit repeated, or a straight run. This is the
     * number typed only to satisfy the input field.
     */
    case SuspiciousCustomerPhone = 'suspicious_customer_phone';

    /**
     * The same phone number on several orders in a day at one branch. A real
     * regular does this occasionally; a staff member reusing one fake number does
     * it constantly, and the count is what separates them.
     */
    case RepeatedCustomerPhone = 'repeated_customer_phone';

    /** One person cancelling an unusual number of orders in a rolling window. */
    case StaffCancellationSpike = 'staff_cancellation_spike';

    /**
     * Orders taken as `no_charge` above a threshold. Every one may be legitimate;
     * the pattern is still worth a look, because this is the payment method with
     * no money attached to it.
     */
    case NoChargeSpike = 'no_charge_spike';

    /** A shift still open long after it should have been closed. */
    case ShiftLeftOpen = 'shift_left_open';

    public function label(): string
    {
        return match ($this) {
            self::OrderStalled => 'An order sits unmoved',
            self::SuspiciousCustomerPhone => 'A customer phone number looks fake',
            self::RepeatedCustomerPhone => 'The same phone number keeps coming back',
            self::StaffCancellationSpike => 'Someone is cancelling a lot of orders',
            self::NoChargeSpike => 'A lot of orders are going through as no charge',
            self::ShiftLeftOpen => 'A shift was left open',
        };
    }

    /**
     * What this event is about, one record at a time. Drives the cooldown key and
     * the merge fields available to the template.
     */
    public function subjectType(): ?string
    {
        return match ($this) {
            self::OrderStalled,
            self::SuspiciousCustomerPhone,
            self::RepeatedCustomerPhone => \App\Models\Order::class,

            self::ShiftLeftOpen => \App\Models\Shift::class,

            // Spikes are about a person over a window, not about one record.
            self::StaffCancellationSpike,
            self::NoChargeSpike => null,
        };
    }

    /**
     * Settings this event cannot run without.
     *
     * An event whose required setting is missing is REFUSED at validation rather
     * than quietly defaulted. A default here is a rule matching a threshold
     * nobody chose — and the direction of the mistake is always the same, because
     * the tempting default (zero, or null-means-all) matches everything.
     *
     * @return array<int, string>
     */
    public function requiredConditions(): array
    {
        return match ($this) {
            self::OrderStalled => ['status', 'minutes'],
            self::SuspiciousCustomerPhone => [],
            self::RepeatedCustomerPhone => ['threshold', 'window_hours'],
            self::StaffCancellationSpike => ['threshold', 'window_hours'],
            self::NoChargeSpike => ['threshold', 'window_hours'],
            self::ShiftLeftOpen => ['hours'],
        };
    }

    /**
     * Merge fields a template for this event may use, beyond the ones every
     * event provides (`{name}`, `{first_name}`, `{branch}`).
     *
     * @return array<int, string>
     */
    public function mergeFields(): array
    {
        return match ($this) {
            self::OrderStalled => ['order_number', 'status', 'minutes', 'customer_phone'],
            self::SuspiciousCustomerPhone => ['order_number', 'customer_phone'],
            self::RepeatedCustomerPhone => ['order_number', 'customer_phone', 'count'],
            self::StaffCancellationSpike => ['count', 'window_hours'],
            self::NoChargeSpike => ['count', 'window_hours', 'amount'],
            self::ShiftLeftOpen => ['hours', 'shift_started'],
        };
    }
}
