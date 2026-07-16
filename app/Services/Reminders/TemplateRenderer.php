<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\Tenant;
use App\Support\Money;

/**
 * Renders an editable reminder template by substituting its merge fields with
 * live invoice values (FR-NOT-03). Money is formatted only here — at the SMS
 * boundary.
 *
 * Three contexts, three field sets: invoice reminders (FIELDS), payment
 * confirmations rendered from a receipt (RECEIPT_FIELDS), and tenant-level
 * balance statements (TENANT_FIELDS).
 */
class TemplateRenderer
{
    /**
     * The merge fields available to template editors.
     */
    public const array FIELDS = [
        '{tenant}',
        '{property}',
        '{period}',
        '{invoice_number}',
        '{amount_due}',
        '{fine}',
        '{due_date}',
        '{payment_account}',
    ];

    /**
     * Fields for the payment-confirmation template (one receipt = one
     * handover, possibly settling several invoices).
     */
    public const array RECEIPT_FIELDS = [
        '{tenant}',
        '{receipt_number}',
        '{amount_paid}',
        '{invoice_numbers}',
        '{invoice_count}',
        '{payment_date}',
        '{tenant_balance}',
        '{payment_account}',
    ];

    /**
     * Fields for the monthly balance-statement template.
     */
    public const array TENANT_FIELDS = [
        '{tenant}',
        '{tenant_balance}',
        '{invoice_count}',
        '{payment_account}',
    ];

    public function render(string $template, Invoice $invoice): string
    {
        $invoice->loadMissing(['lease.tenant', 'lease.property']);

        return strtr($template, [
            '{tenant}' => $invoice->lease->tenant->name,
            '{property}' => $invoice->lease->property->name,
            '{period}' => $invoice->period_start->format('F Y'),
            '{invoice_number}' => $invoice->number,
            '{amount_due}' => Money::fromLaari(max($invoice->outstandingTotalLaari(), 0))->format(),
            '{fine}' => Money::fromLaari(max($invoice->outstandingFineLaari(), 0))->format(),
            '{due_date}' => $invoice->due_date->format('j F Y'),
            '{payment_account}' => (string) config('billing.payment_account'),
        ]);
    }

    /**
     * Render the payment-confirmation template for one receipt.
     */
    public function renderReceipt(string $template, Receipt $receipt): string
    {
        $receipt->loadMissing(['tenant', 'payments.invoice']);

        $count = $receipt->payments->count();

        return strtr($template, [
            '{tenant}' => $receipt->tenant->name,
            '{receipt_number}' => $receipt->number,
            '{amount_paid}' => $receipt->total()->format(),
            '{invoice_numbers}' => $receipt->payments->map(fn ($p) => $p->invoice->number)->implode(', '),
            '{invoice_count}' => $count.' invoice'.($count === 1 ? '' : 's'),
            '{payment_date}' => $receipt->payments->first()->payment_date->format('j F Y'),
            '{tenant_balance}' => $receipt->tenant->outstandingBalance()->format(),
            '{payment_account}' => (string) config('billing.payment_account'),
        ]);
    }

    /**
     * Render the balance-statement template for one tenant.
     */
    public function renderTenant(string $template, Tenant $tenant): string
    {
        $count = $tenant->outstandingInvoiceCount();

        return strtr($template, [
            '{tenant}' => $tenant->name,
            '{tenant_balance}' => $tenant->outstandingBalance()->format(),
            '{invoice_count}' => $count.' invoice'.($count === 1 ? '' : 's'),
            '{payment_account}' => (string) config('billing.payment_account'),
        ]);
    }
}
