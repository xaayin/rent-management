<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Billing\InvoiceFineApplier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Recomputes the accruing fine on every unpaid, past-due invoice so staff
 * always see the current amount (FR-FIN-05). Run daily by the scheduler;
 * idempotent for a given as-of date.
 */
class RefreshInvoiceFines extends Command
{
    protected $signature = 'invoices:refresh-fines {--as-of= : Compute as of this date (defaults to today)}';

    protected $description = 'Mark unpaid past-due invoices Overdue and recompute their accrued fines.';

    public function handle(InvoiceFineApplier $applier): int
    {
        $asOf = ($this->option('as-of') !== null
            ? CarbonImmutable::parse((string) $this->option('as-of'))
            : CarbonImmutable::now(config('app.timezone')))->startOfDay();

        $refreshed = 0;

        Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value, InvoiceStatus::PartlyPaid->value])
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->with('lease')
            ->each(function (Invoice $invoice) use ($applier, $asOf, &$refreshed): void {
                $applier->apply($invoice, $asOf);
                $refreshed++;
            });

        $this->info("Refreshed fines on {$refreshed} invoice(s) as of {$asOf->toDateString()}.");

        return self::SUCCESS;
    }
}
