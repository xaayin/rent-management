<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Models\Invoice;
use App\Support\Money;

/**
 * Renders an editable reminder template by substituting its merge fields with
 * live invoice values (FR-NOT-03). Money is formatted only here — at the SMS
 * boundary.
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
}
