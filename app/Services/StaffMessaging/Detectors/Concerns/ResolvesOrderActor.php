<?php

namespace App\Services\StaffMessaging\Detectors\Concerns;

use App\Models\Order;

trait ResolvesOrderActor
{
    /**
     * The staff member who took this order, as a user id.
     *
     * `assigned_employee_id` first — it is set at creation for POS and
     * staff-taken orders (PosOrderController, OrderCreationService) and is the
     * closest thing to an author the schema records. Falling back to the earliest
     * status transition catches orders that were assigned later.
     */
    protected function orderActorUserId(Order $order): ?int
    {
        if ($order->relationLoaded('assignedEmployee') || $order->assigned_employee_id) {
            $userId = $order->assignedEmployee?->user_id;

            if ($userId) {
                return (int) $userId;
            }
        }

        return $order->statusHistory
            ->sortBy('changed_at')
            ->firstWhere(fn ($entry) => $entry->changed_by_id !== null)
            ?->changed_by_id;
    }

    /**
     * Order sources where a member of staff typed the customer's details.
     *
     * `online` is excluded deliberately and this matters more than it looks: on a
     * website order the customer enters their own number, so a junk one is their
     * typo, not a staff failing. Cautioning somebody for it would be flatly
     * unjust, and one unjust caution costs more credibility than ten fair ones
     * earn.
     *
     * @return array<int, string>
     */
    protected function staffEnteredSources(): array
    {
        return ['phone', 'whatsapp', 'instagram', 'facebook', 'social_media', 'pos'];
    }
}
