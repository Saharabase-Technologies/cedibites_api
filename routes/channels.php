<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('orders.branch.{branchId}', function ($user, $branchId) {
    if ($user->hasAnyRole(['admin', 'tech_admin'])) {
        return true;
    }

    return $user->employee?->branches()->where('branches.id', $branchId)->exists() ?? false;
});

// IMS purchase-order live updates — any user who can view POs may listen.
Broadcast::channel('inventory.purchase-orders', function ($user) {
    return $user->can(\App\Enums\Permission::InventoryPurchaseView->value);
});

// IMS stock-transfer live updates — any user who can view the inventory catalog
// may listen (transfer index/show are gated by the same permission).
Broadcast::channel('inventory.transfers', function ($user) {
    return $user->can(\App\Enums\Permission::ViewInventoryCatalog->value);
});

// IMS requisition live updates — same visibility rule as transfers.
Broadcast::channel('inventory.requisitions', function ($user) {
    return $user->can(\App\Enums\Permission::ViewInventoryCatalog->value);
});

// IMS reconciliation live updates — same visibility rule as transfers.
Broadcast::channel('inventory.reconciliations', function ($user) {
    return $user->can(\App\Enums\Permission::ViewInventoryCatalog->value);
});

// IMS wastage live updates — same visibility rule as transfers. The listener
// refetches through the API, which re-applies the caller's location scope, so a
// branch never learns about another branch's losses from the signal alone.
Broadcast::channel('inventory.wastages', function ($user) {
    return $user->can(\App\Enums\Permission::ViewInventoryCatalog->value);
});

// IMS stock-balance changes. Screens that read balances rather than documents
// (items, dashboard, reports, daily closing) have no document event to follow;
// this is theirs. The signal is scalars only and listeners refetch through the
// API, which re-applies the caller's own location scope.
Broadcast::channel('inventory.stock', function ($user) {
    return $user->can(\App\Enums\Permission::ViewInventoryCatalog->value);
});
