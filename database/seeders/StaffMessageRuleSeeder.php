<?php

namespace Database\Seeders;

use App\Enums\StaffMessageEvent;
use App\Enums\StaffMessageKind;
use App\Enums\StaffMessageTarget;
use App\Models\StaffMessageRule;
use Illuminate\Database\Seeder;

/**
 * Starter rules, every one of them switched OFF.
 *
 * Seeded rather than left blank because an empty rules screen makes the feature
 * look like homework. These are drafts to dry-run, argue with and edit — not
 * recommendations, and certainly not defaults anybody should switch on unread.
 *
 * The wording matters as much as the thresholds. Every message assumes the
 * innocent explanation first and tells the person what to DO. "You have not
 * moved this order — check with the kitchen" is a colleague; "this order has
 * been sitting for 47 minutes" is a reprimand with no instruction in it, and
 * people stop reading those within a week.
 */
class StaffMessageRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rules() as $rule) {
            StaffMessageRule::updateOrCreate(
                ['name' => $rule['name']],
                // `is_active` is never in this payload. Re-running the seeder
                // must not switch a rule back on that somebody deliberately
                // switched off, and must not switch on one they are still
                // testing.
                $rule + ['is_active' => false],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rules(): array
    {
        return [
            [
                'name' => 'Order taken but not moved',
                'description' => 'A new order left sitting in Received.',
                'event' => StaffMessageEvent::OrderStalled->value,
                'conditions' => ['status' => 'received', 'minutes' => 15],
                'target' => ['types' => [StaffMessageTarget::Actor->value]],
                'kind' => StaffMessageKind::Caution->value,
                'subject' => 'Order {order_number} has not moved',
                'body_template' => 'Hi {first_name}, order {order_number} at {branch} has been sitting in Received for {minutes} minutes. Check with the kitchen whether there is a problem — and if it is already being prepared, please move it along so the timing is right.',
                'requires_acknowledgement' => true,
                'allow_custom_reply' => true,
                'quick_replies' => ['Moving it now', 'Kitchen is on it', 'There is a problem'],
                'sms_fallback_after_minutes' => 20,
                'cooldown_minutes' => 120,
                'priority' => 100,
            ],
            [
                'name' => 'Order taken but not moved — manager copy',
                'description' => 'The same stall, an hour on, to the branch manager.',
                'event' => StaffMessageEvent::OrderStalled->value,
                'conditions' => ['status' => 'received', 'minutes' => 60],
                'target' => ['types' => [StaffMessageTarget::BranchManagers->value]],
                'kind' => StaffMessageKind::Notice->value,
                'subject' => 'An order at {branch} has stalled',
                'body_template' => 'Order {order_number} has been in Received for {minutes} minutes at {branch}. Worth a look at what is holding it up.',
                'requires_acknowledgement' => false,
                'allow_custom_reply' => true,
                'quick_replies' => ['Looking into it', 'Already sorted'],
                'cooldown_minutes' => 720,
                // Lower than the staff caution on purpose. When both match, the
                // person doing the work hears about it first.
                'priority' => 50,
            ],
            [
                'name' => 'Food ready, nobody has collected it',
                'description' => 'An order sitting Ready while it goes cold.',
                'event' => StaffMessageEvent::OrderStalled->value,
                'conditions' => ['status' => 'ready', 'minutes' => 20],
                'target' => ['types' => [StaffMessageTarget::BranchStaff->value]],
                'kind' => StaffMessageKind::Caution->value,
                'subject' => 'Order {order_number} is ready and waiting',
                'body_template' => '{order_number} at {branch} has been ready for {minutes} minutes. Please get it out or hand it to the rider.',
                'requires_acknowledgement' => true,
                'quick_replies' => ['On it', 'Rider is on the way'],
                'cooldown_minutes' => 60,
                'priority' => 90,
            ],
            [
                'name' => 'Delivery taking too long',
                'description' => 'Out for delivery well past the expected time.',
                'event' => StaffMessageEvent::OrderStalled->value,
                'conditions' => ['status' => 'out_for_delivery', 'minutes' => 60],
                'target' => ['types' => [
                    StaffMessageTarget::Actor->value,
                    StaffMessageTarget::BranchManagers->value,
                ]],
                'kind' => StaffMessageKind::Caution->value,
                'subject' => 'Delivery {order_number} is running long',
                'body_template' => '{order_number} has been out for delivery for {minutes} minutes. If it has been delivered, please mark it delivered. If not, let the branch know where you are.',
                'requires_acknowledgement' => true,
                'quick_replies' => ['Delivered — marking it now', 'Still on the road', 'Cannot reach the customer'],
                'sms_fallback_after_minutes' => 15,
                'cooldown_minutes' => 120,
                'priority' => 95,
            ],
            [
                'name' => 'Customer phone number looks made up',
                'description' => 'A number that could not reach anybody.',
                'event' => StaffMessageEvent::SuspiciousCustomerPhone->value,
                'conditions' => [],
                'target' => ['types' => [StaffMessageTarget::Actor->value]],
                'kind' => StaffMessageKind::Caution->value,
                'subject' => 'Check the phone number on {order_number}',
                'body_template' => 'Hi {first_name}, the number on order {order_number} ({customer_phone}) is {reason}. We cannot reach the customer or send them updates without a real number. Please take it again on the next order.',
                'requires_acknowledgement' => true,
                'allow_custom_reply' => true,
                'quick_replies' => ['Understood', 'Customer refused to give one'],
                'cooldown_minutes' => 480,
                'priority' => 80,
            ],
            [
                'name' => 'Same phone number over and over',
                'description' => 'One number reused across several orders in a day.',
                'event' => StaffMessageEvent::RepeatedCustomerPhone->value,
                'conditions' => ['threshold' => 4, 'window_hours' => 12],
                'target' => ['types' => [
                    StaffMessageTarget::Actor->value,
                    StaffMessageTarget::BranchManagers->value,
                ]],
                'kind' => StaffMessageKind::Caution->value,
                'subject' => 'One number on {count} orders today',
                'body_template' => '{customer_phone} has been used on {count} orders at {branch} in the last {window_hours} hours. If that is a regular customer, all good — please confirm. If it is a stand-in number, we need the real ones.',
                'requires_acknowledgement' => true,
                'quick_replies' => ['Genuine regular customer', 'Understood'],
                'cooldown_minutes' => 1440,
                'priority' => 70,
            ],
            [
                'name' => 'Shift left open',
                'description' => 'A shift still running long after it should have closed.',
                'event' => StaffMessageEvent::ShiftLeftOpen->value,
                'conditions' => ['hours' => 14],
                'target' => ['types' => [StaffMessageTarget::Actor->value]],
                'kind' => StaffMessageKind::Caution->value,
                'subject' => 'Your shift is still open',
                'body_template' => 'Hi {first_name}, your shift from {shift_started} is still open after {hours} hours. Please close it so today\'s sales land on the right day.',
                'requires_acknowledgement' => true,
                'quick_replies' => ['Closing it now', 'Still working'],
                'sms_fallback_after_minutes' => 60,
                'cooldown_minutes' => 720,
                'priority' => 40,
            ],
            [
                'name' => 'A lot of cancellations from one person',
                'description' => 'Reports a rate. Does not judge any single cancellation.',
                'event' => StaffMessageEvent::StaffCancellationSpike->value,
                'conditions' => ['threshold' => 5, 'window_hours' => 12],
                'target' => ['types' => [StaffMessageTarget::BranchManagers->value]],
                'kind' => StaffMessageKind::Notice->value,
                'subject' => 'Cancellations at {branch}',
                'body_template' => 'One member of staff has cancelled {count} orders in the last {window_hours} hours at {branch}. Probably a busy day — worth asking what is going on.',
                'requires_acknowledgement' => false,
                'quick_replies' => ['Looked into it'],
                'cooldown_minutes' => 1440,
                'priority' => 30,
            ],
            [
                'name' => 'No-charge orders adding up',
                'description' => 'The payment method with no money attached to it.',
                'event' => StaffMessageEvent::NoChargeSpike->value,
                'conditions' => ['threshold' => 4, 'window_hours' => 24],
                'target' => ['types' => [
                    StaffMessageTarget::BranchManagers->value,
                    StaffMessageTarget::Admins->value,
                ]],
                'kind' => StaffMessageKind::Notice->value,
                'subject' => 'No-charge orders at {branch}',
                'body_template' => '{count} no-charge orders worth GHS {amount} went through at {branch} in the last {window_hours} hours. Worth confirming they were all meant to be.',
                'requires_acknowledgement' => false,
                'quick_replies' => ['Checked — all correct', 'Looking into it'],
                'cooldown_minutes' => 1440,
                'priority' => 20,
            ],
        ];
    }
}
