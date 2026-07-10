<?php

declare(strict_types=1);

namespace App\Livewire\Tenants;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-tenant account statement (FR-PAY-05): every invoice, payment, fine and
 * the running balance — consolidated across all the tenant's leases
 * (FR-TEN-05). This replaces the manual per-tenant ledger sheet.
 */
#[Layout('components.layouts.app')]
class Statement extends Component
{
    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        abort_unless(
            (bool) auth()->user()?->hasPermissionTo(Permission::ViewReports->value),
            403,
        );

        $this->tenant = $tenant;
    }

    public function render(): View
    {
        $entries = $this->buildEntries();

        return view('livewire.tenants.statement', [
            'entries' => $entries,
            'balance' => Money::fromLaari(
                (int) $entries->sum('debit') - (int) $entries->sum('credit'),
            ),
        ]);
    }

    /**
     * Chronological ledger rows with a running balance in laari.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildEntries(): Collection
    {
        $entries = collect();

        $leases = $this->tenant->leases()
            ->with(['property', 'invoices.payments'])
            ->get();

        foreach ($leases as $lease) {
            foreach ($lease->invoices as $invoice) {
                $detail = 'Rent '.$invoice->rent()->format();

                if ($invoice->charges_laari > 0) {
                    $detail .= ' · CSR '.$invoice->charges()->format();
                }

                if ($invoice->fine_laari > 0) {
                    $detail .= ' · Fine '.$invoice->fine()->format();
                }

                $entries->push([
                    'sort' => $invoice->period_start->toDateString().'-1-'.$invoice->id,
                    'date' => $invoice->period_start->toDateString(),
                    'label' => "Invoice {$invoice->number} — {$lease->property->name}, {$invoice->periodLabel()}",
                    'detail' => $detail,
                    'debit' => $invoice->total_laari,
                    'credit' => 0,
                ]);

                foreach ($invoice->payments as $payment) {
                    if ($payment->isReversal()) {
                        $entries->push([
                            'sort' => $payment->payment_date->toDateString().'-2-'.$payment->id,
                            'date' => $payment->payment_date->toDateString(),
                            'label' => "Reversal of receipt {$payment->reference} — invoice {$invoice->number}",
                            'detail' => (string) $payment->reversal_reason,
                            'debit' => -$payment->amount_laari,
                            'credit' => 0,
                        ]);
                    } else {
                        $entries->push([
                            'sort' => $payment->payment_date->toDateString().'-2-'.$payment->id,
                            'date' => $payment->payment_date->toDateString(),
                            'label' => "Payment — receipt {$payment->receipt_number} ({$payment->method->label()})",
                            'detail' => 'Rent '.$payment->principalAllocated()->format().' · Fine '.$payment->fineAllocated()->format(),
                            'credit' => $payment->amount_laari,
                            'debit' => 0,
                        ]);
                    }
                }
            }
        }

        $running = 0;

        return $entries
            ->sortBy('sort')
            ->values()
            ->map(function (array $entry) use (&$running): array {
                $running += $entry['debit'] - $entry['credit'];
                $entry['balance'] = $running;

                return $entry;
            });
    }
}
