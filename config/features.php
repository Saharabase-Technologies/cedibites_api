<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Master switches for incremental, flag-OFF rollouts. Each entry should be
    | toggled via env so production, beta, and local environments can flip
    | independently. Default to OFF for any new feature.
    |
    */

    'inventory' => [
        'enabled' => env('IMS_ENABLED', false),
    ],

];
