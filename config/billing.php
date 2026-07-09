<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment allocation policy (§5.4.23)
    |--------------------------------------------------------------------------
    |
    | How a payment is split across an invoice's outstanding amounts. The
    | locked default settles rent (principal, incl. CSR charges) first and the
    | fine after; "fine_first" is the alternative central policy.
    |
    */

    'payment_allocation' => env('BILLING_PAYMENT_ALLOCATION', 'rent_first'),

    /*
    |--------------------------------------------------------------------------
    | Payment account
    |--------------------------------------------------------------------------
    |
    | Shown on invoices and receipts so tenants know where to pay. Replace with
    | the council's actual collection account before go-live.
    |
    */

    'payment_account' => env('BILLING_PAYMENT_ACCOUNT', 'Bank of Maldives — 7701-000000-001 (Council Revenue)'),

];
