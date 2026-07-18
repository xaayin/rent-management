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

    /*
    |--------------------------------------------------------------------------
    | Due-date anchor (council rule — see App\Enums\DueDateAnchor)
    |--------------------------------------------------------------------------
    |
    | Which month an invoice falls due in, relative to the month it bills. The
    | day within that month is each lease's own `due_day`.
    |
    |   start_day_based  rent starts ON the 1st → due that month;
    |                    starts mid-month → due the following month.
    |                    (The council's current rule, as on the paper ledgers.)
    |   same_month       always due within the billed month.
    |   next_month       always due the month after.
    |
    | Changing this affects invoices generated from then on; already-issued
    | invoices keep their recorded due date.
    |
    */

    'due_date_anchor' => env('BILLING_DUE_DATE_ANCHOR', 'start_day_based'),

];
