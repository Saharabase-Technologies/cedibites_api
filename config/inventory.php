<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Purchase Order approval threshold (GHS)
    |--------------------------------------------------------------------------
    |
    | POs whose estimated total is at or above this amount require Admin
    | approval before they can move from draft → sent. Mirrors the frontend
    | PO_APPROVAL_THRESHOLD constant.
    |
    */
    'po_approval_threshold' => (float) env('IMS_PO_APPROVAL_THRESHOLD', 10000),

    /*
    |--------------------------------------------------------------------------
    | Default wastage approval threshold (GHS)
    |--------------------------------------------------------------------------
    */
    'wastage_threshold_default' => (float) env('IMS_WASTAGE_THRESHOLD', 500),

];
