<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidPaymentException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceFineApplier;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Collect ONE handover of money from a tenant and spread it across their
 * outstanding invoices, oldest first (FR-PAY-01/02).
 *
 * Lives in a trait because the two roles who may record payments reach it from
 * different places (§6.1): a Finance Officer works on the Invoices screen and
 * cannot open the tenants registry at all, while a Supervisor has both and
 * naturally starts from the tenant. Same surface, same money path, either door.
 *
 * All money logic stays in PaymentRecorder — this is only form state and the
 * preview.
 */
trait CollectsTenantPayments
{
    public bool $collecting = false;

    /** The tenant whose money is being taken. */
    public ?int $collectTenantId = null;

    public string $collect_amount = '';

    public string $collect_date = '';

    public string $collect_method = 'cash';

    public string $collect_reference = '';

    /** @var list<int> */
    public array $collect_invoice_ids = [];

    /**
     * Open the surface with every outstanding invoice ticked and the full
     * balance pre-filled — the common case is "the tenant cleared everything".
     */
    public function startCollectFor(int $tenantId): void
    {
        $this->authorize('create', Payment::class);

        $tenant = Tenant::findOrFail($tenantId);

        $this->collecting = true;
        $this->collectTenantId = $tenant->id;
        $this->collect_date = today()->toDateString();
        $this->collect_method = 'cash';
        $this->collect_reference = '';
        $this->resetValidation();

        $this->collect_invoice_ids = $this->outstandingInvoicesFor($tenant)->pluck('id')->all();
        $this->collect_amount = $this->selectedOutstanding($tenant)->toRufiyaa();
    }

    public function cancelCollect(): void
    {
        $this->collecting = false;
        $this->reset('collectTenantId', 'collect_amount', 'collect_date', 'collect_method', 'collect_reference', 'collect_invoice_ids');
        $this->resetValidation();
    }

    /**
     * Snap the amount back to whatever the current selection owes, so ticking
     * an invoice off doesn't silently leave an overpayment in the box.
     */
    public function updatedCollectInvoiceIds(): void
    {
        if ($this->collectTenantId !== null) {
            $this->collect_amount = $this->selectedOutstanding(Tenant::findOrFail($this->collectTenantId))->toRufiyaa();
        }
    }

    public function confirmCollect(): void
    {
        $this->authorize('create', Payment::class);

        $tenant = Tenant::findOrFail($this->collectTenantId);

        $validated = $this->validate([
            'collect_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'collect_date' => ['required', 'date'],
            'collect_method' => ['required', Rule::enum(PaymentMethod::class)],
            'collect_reference' => ['nullable', 'string', 'max:255'],
            'collect_invoice_ids' => ['required', 'array', 'min:1'],
        ], [
            'collect_amount.regex' => 'Enter an amount like 1500.00.',
            'collect_invoice_ids.required' => 'Pick at least one invoice to put this against.',
            'collect_invoice_ids.min' => 'Pick at least one invoice to put this against.',
        ]);

        try {
            $receipt = app(PaymentRecorder::class)->recordForTenant(
                $tenant,
                Money::fromRufiyaa($validated['collect_amount']),
                CarbonImmutable::parse($validated['collect_date']),
                PaymentMethod::from($validated['collect_method']),
                $validated['collect_reference'] ?: null,
                $this->collectingUser(),
                array_map('intval', $validated['collect_invoice_ids']),
            );
        } catch (InvalidPaymentException $e) {
            $this->addError('collect_amount', $e->getMessage());

            return;
        }

        $count = $receipt->payments->count();
        $this->cancelCollect();

        session()->flash('status', "Receipt {$receipt->number} issued · {$receipt->total()->format()} across {$count} invoice".($count === 1 ? '' : 's').'.');
    }

    /**
     * The tenant's unsettled invoices, oldest due first — the order the money
     * will be applied in, so the screen lists them the way it spends them.
     *
     * @return Collection<int, Invoice>
     */
    protected function outstandingInvoicesFor(Tenant $tenant): Collection
    {
        return Invoice::query()
            ->with('lease.property')
            ->whereHas('lease', fn ($query) => $query->where('tenant_id', $tenant->id))
            ->whereIn('status', [
                InvoiceStatus::Issued->value,
                InvoiceStatus::PartlyPaid->value,
                InvoiceStatus::Overdue->value,
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Live oldest-first split of the entered amount, so the clerk sees exactly
     * where the money lands before committing it.
     *
     * @return array<string, mixed>|null
     */
    protected function buildCollectPreview(): ?array
    {
        if (! $this->collecting || $this->collectTenantId === null) {
            return null;
        }

        $tenant = Tenant::find($this->collectTenantId);

        if ($tenant === null) {
            return null;
        }

        $entered = $this->enteredLaari();
        $remaining = $entered;
        $rows = [];
        $totalDue = 0;

        foreach ($this->outstandingInvoicesFor($tenant) as $invoice) {
            $selected = in_array($invoice->id, array_map('intval', $this->collect_invoice_ids), true);
            $due = $this->dueAsOfPaymentDate($invoice);
            $allocated = 0;

            if ($selected && $remaining > 0 && $due > 0) {
                $allocated = min($remaining, $due);
                $remaining -= $allocated;
            }

            if ($selected) {
                $totalDue += $due;
            }

            $rows[] = [
                'invoice' => $invoice,
                'selected' => $selected,
                'due' => Money::fromLaari($due),
                'allocated' => Money::fromLaari($allocated),
                'remaining' => Money::fromLaari(max($due - $allocated, 0)),
                'settles' => $selected && $due > 0 && $allocated >= $due,
            ];
        }

        return [
            'tenant' => $tenant,
            'rows' => $rows,
            'total_due' => Money::fromLaari($totalDue),
            'entered' => Money::fromLaari($entered),
            'unapplied' => Money::fromLaari(max($remaining, 0)),
            'exceeds' => $entered > $totalDue,
        ];
    }

    /**
     * What the ticked invoices owe as of the chosen payment date.
     */
    private function selectedOutstanding(Tenant $tenant): Money
    {
        $laari = $this->outstandingInvoicesFor($tenant)
            ->whereIn('id', array_map('intval', $this->collect_invoice_ids))
            ->sum(fn (Invoice $invoice): int => $this->dueAsOfPaymentDate($invoice));

        return Money::fromLaari(max((int) $laari, 0));
    }

    /**
     * One invoice's total owed on the chosen payment date — fine included,
     * computed the same way the recorder will when it writes (FR-PAY-02).
     * Preview only; nothing is persisted until the clerk confirms.
     */
    private function dueAsOfPaymentDate(Invoice $invoice): int
    {
        $principal = max($invoice->outstandingPrincipalLaari(), 0);

        // The fine only moves while principal is outstanding — once settled it
        // is frozen, so the stored figure is already the right one (§5.3.15).
        $fine = $principal > 0
            ? (app(InvoiceFineApplier::class)->previewFine($invoice, $this->paymentDate())?->totalLaari ?? $invoice->fine_laari)
            : $invoice->fine_laari;

        return $principal + max($fine - $invoice->paidFineLaari(), 0);
    }

    private function enteredLaari(): int
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $this->collect_amount) === 1
            ? Money::fromRufiyaa($this->collect_amount)->laari
            : 0;
    }

    private function paymentDate(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->collect_date ?: today()->toDateString())->startOfDay();
        } catch (Throwable) {
            return CarbonImmutable::parse(today()->toDateString());
        }
    }

    private function collectingUser(): ?User
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user;
    }
}
