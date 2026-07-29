<?php

use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

// --- Push subscription management (any authenticated user) ---
Route::get('push/vapid-key', [PushSubscriptionController::class, 'vapidPublicKey']);
Route::post('push/subscribe', [PushSubscriptionController::class, 'store']);
Route::post('push/unsubscribe', [PushSubscriptionController::class, 'destroy']);

// --- Customer-accessible routes (any authenticated user, suspended customers blocked) ---
Route::middleware('customer.active')->group(function () {
    Route::post('menu-items/{menuItem}/rate', [MenuItemController::class, 'rate']);

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
});

// --- Staff-only routes (staff-issued token + specific permissions) ---
Route::middleware('token.staff')->group(function () {
    Route::middleware('permission:access_kitchen')->group(function () {
        Route::get('kitchen/orders', [OrderController::class, 'kitchenOrders']);
    });

    Route::middleware('permission:access_order_manager')->group(function () {
        Route::get('order-manager/orders', [OrderController::class, 'orderManagerOrders']);
    });

    Route::middleware('permission:update_orders')->group(function () {
        Route::match(['put', 'patch'], 'orders/{order}', [OrderController::class, 'update']);
    });

    Route::middleware('permission:delete_orders')->group(function () {
        Route::delete('orders/{order}', [OrderController::class, 'destroy']);
    });

    // Cancellation is staff-request + admin-approve only (see admin.php)
    Route::middleware('permission:update_orders')->group(function () {
        Route::post('orders/{order}/refund', [\App\Http\Controllers\Api\PaymentController::class, 'refundOrder'])->name('orders.refund');
    });
});
