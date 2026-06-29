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
