<?php

use App\Enums\Inventory\PurchaseOrderStatus;

it('only allows editing drafts', function () {
    expect(PurchaseOrderStatus::Draft->isEditable())->toBeTrue();
    expect(PurchaseOrderStatus::Sent->isEditable())->toBeFalse();
});

it('treats sent and partially_received as receivable', function () {
    expect(PurchaseOrderStatus::Sent->isReceivable())->toBeTrue();
    expect(PurchaseOrderStatus::PartiallyReceived->isReceivable())->toBeTrue();
    expect(PurchaseOrderStatus::Draft->isReceivable())->toBeFalse();
    expect(PurchaseOrderStatus::Received->isReceivable())->toBeFalse();
});

it('permits cancellation only before any receipt', function () {
    expect(PurchaseOrderStatus::Draft->isCancellable())->toBeTrue();
    expect(PurchaseOrderStatus::PendingApproval->isCancellable())->toBeTrue();
    expect(PurchaseOrderStatus::Sent->isCancellable())->toBeTrue();
    expect(PurchaseOrderStatus::PartiallyReceived->isCancellable())->toBeFalse();
});

it('permits closing only received or partially-received orders', function () {
    expect(PurchaseOrderStatus::PartiallyReceived->isClosable())->toBeTrue();
    expect(PurchaseOrderStatus::Received->isClosable())->toBeTrue();
    expect(PurchaseOrderStatus::Sent->isClosable())->toBeFalse();
});

it('validates direct transitions', function () {
    expect(PurchaseOrderStatus::Draft->canTransitionTo(PurchaseOrderStatus::Sent))->toBeTrue();
    expect(PurchaseOrderStatus::Draft->canTransitionTo(PurchaseOrderStatus::PendingApproval))->toBeTrue();
    expect(PurchaseOrderStatus::Draft->canTransitionTo(PurchaseOrderStatus::Received))->toBeFalse();
    expect(PurchaseOrderStatus::Closed->canTransitionTo(PurchaseOrderStatus::Sent))->toBeFalse();
});
