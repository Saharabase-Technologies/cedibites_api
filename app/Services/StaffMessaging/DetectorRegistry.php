<?php

namespace App\Services\StaffMessaging;

use App\Enums\StaffMessageEvent;
use App\Services\StaffMessaging\Detectors\CancellationSpikeDetector;
use App\Services\StaffMessaging\Detectors\DetectsStaffEvent;
use App\Services\StaffMessaging\Detectors\NoChargeSpikeDetector;
use App\Services\StaffMessaging\Detectors\OrderStalledDetector;
use App\Services\StaffMessaging\Detectors\RepeatedPhoneDetector;
use App\Services\StaffMessaging\Detectors\ShiftLeftOpenDetector;
use App\Services\StaffMessaging\Detectors\SuspiciousPhoneDetector;

/**
 * Event to detector. One place, so the live evaluator and the dry run cannot
 * possibly be looking at different implementations of the same rule.
 */
class DetectorRegistry
{
    public function for(StaffMessageEvent $event): DetectsStaffEvent
    {
        return match ($event) {
            StaffMessageEvent::OrderStalled => new OrderStalledDetector,
            StaffMessageEvent::SuspiciousCustomerPhone => new SuspiciousPhoneDetector,
            StaffMessageEvent::RepeatedCustomerPhone => new RepeatedPhoneDetector,
            StaffMessageEvent::StaffCancellationSpike => new CancellationSpikeDetector,
            StaffMessageEvent::NoChargeSpike => new NoChargeSpikeDetector,
            StaffMessageEvent::ShiftLeftOpen => new ShiftLeftOpenDetector,
        };
    }
}
