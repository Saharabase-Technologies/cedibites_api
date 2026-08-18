<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * The one thing that promotes a contact to a customer: an order.
 *
 * Not registering an account, not replying to a campaign, not being ticked over
 * by an admin — buying food. That definition is what makes the customer figures
 * mean anything: a contact counts once they have done the thing the business
 * exists to make people do, and not a moment earlier.
 *
 * Nothing here creates or edits a Customer. Conversion is a fact we observe and
 * record on our side of the line; the order already did the real work, and the
 * customer metrics already count it.
 */
class ContactConverter
{
    /**
     * Stamp the contact behind this order, if there is one.
     *
     * Safe to call for every order ever placed — the overwhelming majority match
     * nothing, and a converted contact is never re-stamped, so replaying this
     * over history is idempotent.
     *
     * @return bool whether an unconverted contact was promoted
     */
    public function convertFromOrder(Order $order): bool
    {
        if ($order->status === 'cancelled') {
            return false;
        }

        $phone = $this->phoneFor($order);

        if ($phone === null) {
            return false;
        }

        $contact = Contact::unconverted()->with('import:id,label')->where('phone', $phone)->first();

        if ($contact === null) {
            return false;
        }

        $this->stamp($contact, [
            // The order's own timestamp, not now(). This is the date they became
            // a customer, and a backfill run months later must not rewrite it to
            // the day the backfill happened.
            'converted_at' => $order->created_at ?? now(),
            'converted_order_id' => $order->id,
            'customer_id' => $order->customer_id,
        ], 'order');

        return true;
    }

    /**
     * Write the conversion, and record that it happened.
     *
     * The activity entry is the point of this method existing. `converted_at` on
     * the row tells you the current state and nothing else — overwrite it, undo
     * an import, delete the contact, and the fact that a bought list turned a
     * stranger into a customer on a particular Tuesday is gone. The log is
     * append-only and survives all of that, which is what makes "did that list
     * work?" answerable six months later rather than a matter of opinion.
     *
     * `days_to_convert` is stamped on the entry rather than derived at read
     * time, because it is measured from the contact's import date and that
     * disappears with the contact.
     */
    private function stamp(Contact $contact, array $attributes, string $via): void
    {
        $importedAt = $contact->created_at;
        $convertedAt = $attributes['converted_at'];

        $contact->update($attributes);

        activity('contacts')
            ->performedOn($contact)
            ->event('contact_converted')
            ->withProperties([
                'phone' => $contact->phone,
                'name' => $contact->name,
                'contact_import_id' => $contact->contact_import_id,
                'import_label' => $contact->import?->label,
                'order_id' => $attributes['converted_order_id'] ?? null,

                // Negative would mean they ordered before we imported them,
                // which is the `already_customer` case, not an acquisition.
                'days_to_convert' => $importedAt && $convertedAt
                    ? (int) $importedAt->diffInDays($convertedAt, false)
                    : null,

                'was_customer_before_import' => (bool) $contact->was_customer_before_import,
                'via' => $via,
            ])
            ->log('Contact converted: '.$contact->phone);
    }

    /**
     * The same, but swallowing anything that goes wrong.
     *
     * Used from the order observer. Recording where a contact came from is
     * bookkeeping; taking an order is the business. A failure here must never be
     * what stops a sale going through, so it is logged and dropped.
     */
    public function convertFromOrderQuietly(Order $order): void
    {
        try {
            $this->convertFromOrder($order);
        } catch (\Throwable $e) {
            Log::error('ContactConverter failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Catch up every contact whose number has ordered since.
     *
     * The safety net under the observer. The observer only fires on orders
     * created after this feature shipped and only inside the app — a row written
     * by a migration, a seeder or an admin poking the database directly never
     * passes through it. Run from `contacts:reconcile`.
     *
     * @return int how many were promoted
     */
    public function reconcile(): int
    {
        $index = (new CustomerPhoneIndex)->build();
        $converted = 0;

        Contact::unconverted()
            ->with('import:id,label')
            ->chunkById(500, function ($contacts) use ($index, &$converted): void {
                foreach ($contacts as $contact) {
                    $order = $index[$contact->phone] ?? null;

                    if ($order === null) {
                        continue;
                    }

                    $this->stamp($contact, [
                        'converted_at' => $order['ordered_at'] ?? now(),
                        'converted_order_id' => $order['order_id'],
                        'customer_id' => $order['customer_id'],
                    ], 'reconcile');

                    $converted++;
                }
            });

        return $converted;
    }

    /**
     * The number an order should be attributed to.
     *
     * Falls back to the registered customer's own number when the order carries
     * no contact phone — an account holder ordering through the app often does
     * not retype it.
     */
    private function phoneFor(Order $order): ?string
    {
        $raw = trim((string) $order->contact_phone);

        if ($raw === '') {
            $order->loadMissing('customer.user');
            $raw = trim((string) $order->customer?->user?->phone);
        }

        return $raw === '' ? null : PhoneNormaliser::normalise($raw);
    }
}
