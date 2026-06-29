<?php

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\PurchaseOrders\PurchaseOrderService;
use App\Enums\Inventory\PurchaseOrderStatus;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Supplier;
use App\Models\User;

beforeEach(function () {
    config(['inventory.po_approval_threshold' => 10000]);
    $this->service = app(PurchaseOrderService::class);
    $this->user = User::factory()->create();
    $this->supplier = Supplier::factory()->create();
    $this->warehouse = Location::factory()->warehouse()->create();
    $this->item = Item::factory()->create();
});

function makePo(array $lines): array
{
    return [
        'supplier_id' => test()->supplier->id,
        'destination_location_id' => test()->warehouse->id,
        'items' => $lines,
    ];
}

it('creates a draft PO with computed totals and no approval under threshold', function () {
    $po = $this->service->create(makePo([
        ['item_id' => $this->item->id, 'ordered_qty' => 10, 'estimated_unit_cost' => 5],
    ]), $this->user);

    expect($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and((float) $po->estimated_total)->toBe(50.0)
        ->and($po->requires_approval)->toBeFalse()
        ->and($po->items)->toHaveCount(1)
        ->and((float) $po->items->first()->line_total)->toBe(50.0);
});

it('submits a sub-threshold PO straight to sent', function () {
    $po = $this->service->create(makePo([
        ['item_id' => $this->item->id, 'ordered_qty' => 10, 'estimated_unit_cost' => 5],
    ]), $this->user);

    $po = $this->service->submit($po);

    expect($po->status)->toBe(PurchaseOrderStatus::Sent);
});

it('routes an at-threshold PO through admin approval', function () {
    $admin = User::factory()->create();
    $po = $this->service->create(makePo([
        ['item_id' => $this->item->id, 'ordered_qty' => 1000, 'estimated_unit_cost' => 10],
    ]), $this->user);

    expect($po->requires_approval)->toBeTrue();

    $po = $this->service->submit($po);
    expect($po->status)->toBe(PurchaseOrderStatus::PendingApproval);

    $po = $this->service->approve($po, $admin);
    expect($po->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($po->approved_by)->toBe($admin->id)
        ->and($po->approved_at)->not->toBeNull();
});

it('refuses to approve a draft PO', function () {
    $po = $this->service->create(makePo([
        ['item_id' => $this->item->id, 'ordered_qty' => 1, 'estimated_unit_cost' => 1],
    ]), $this->user);

    $this->service->approve($po, $this->user);
})->throws(InventoryException::class);

it('cancels a draft with a reason', function () {
    $po = $this->service->create(makePo([
        ['item_id' => $this->item->id, 'ordered_qty' => 1, 'estimated_unit_cost' => 1],
    ]), $this->user);

    $po = $this->service->cancel($po, $this->user, 'Duplicated order');

    expect($po->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($po->cancel_reason)->toBe('Duplicated order')
        ->and($po->cancelled_by)->toBe($this->user->id);
});

it('lets an approver edit a pending PO and approves it in one step', function () {
    $admin = User::factory()->create();
    $po = $this->service->create(makePo([
        ['item_id' => $this->item->id, 'ordered_qty' => 1000, 'estimated_unit_cost' => 11],
    ]), $this->user);
    $po = $this->service->submit($po); // → pending_approval (₵11,000)
    expect($po->status)->toBe(PurchaseOrderStatus::PendingApproval);

    $po = $this->service->editAndApprove($po, [
        'items' => [['item_id' => $this->item->id, 'ordered_qty' => 800, 'estimated_unit_cost' => 11]],
        'notes' => 'Trimmed by admin',
    ], $admin);

    expect($po->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($po->approved_by)->toBe($admin->id)
        ->and((float) $po->estimated_total)->toBe(8800.0)
        ->and($po->notes)->toBe('Trimmed by admin');
});

it('refuses edit-and-approve on a non-pending PO', function () {
    $po = $this->service->create(makePo([
        ['item_id' => $this->item->id, 'ordered_qty' => 1, 'estimated_unit_cost' => 1],
    ]), $this->user);

    $this->service->editAndApprove($po, ['notes' => 'nope'], $this->user);
})->throws(InventoryException::class);

it('refuses to edit a non-draft PO', function () {
    $po = $this->service->create(makePo([
        ['item_id' => $this->item->id, 'ordered_qty' => 1, 'estimated_unit_cost' => 1],
    ]), $this->user);
    $po = $this->service->submit($po);

    $this->service->update($po, ['notes' => 'too late']);
})->throws(InventoryException::class);
