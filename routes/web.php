<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Local-only email previews — render notification templates in the browser
 * so they can be reviewed before going live. Never registered in production.
 *
 *   /dev/emails/account-created                       → generic staff welcome
 *   /dev/emails/account-created?role=Branch+Partner   → role-aware welcome
 */
if (app()->environment('local')) {
    Route::get('/dev/emails/account-created', function (Request $request) {
        $user = (object) [
            'name' => 'Ama Mensah',
            'email' => 'ama@example.com',
            'phone' => '+233200000000',
        ];

        return view('emails.staff.account-created', [
            'user' => $user,
            'temporaryPassword' => 'BrightStar27!',
            'roleLabel' => $request->query('role') ?: null,
        ]);
    });
}
