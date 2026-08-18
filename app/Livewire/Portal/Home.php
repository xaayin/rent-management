<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvalidTransferClaimException;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\Tenant;
use App\Services\Portal\TransferClaimService;
use App\Services\Reporting\TenantLedger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The tenant's own view of their account (T2, read-only): leases, what they
 * owe, their invoices and receipts, and the same running-balance ledger the
 * counter sees. Everything is scoped to the signed-in tenant — the queries
 * literally cannot reach anyone else's records.
 */
#[Layout('components.layouts.portal')]
class Home extends Component
{
    /** account | invoices | receipts | statement | pay */
    public string $tab = 'account';

    // "I paid by bank transfer" form (T3).
    public string $claim_amount = '';

    public string $claim_date = '';

    public string $claim_reference = '';

    public string $claim_note = '';

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['account', 'invoices', 'receipts', 'statement', 'pay'], true) ? $tab : 'account';
        $this->resetValidation();
    }

    public function submitClaim(TransferClaimService $claims): void
    {
        $tenant = $this->tenant();

        $validated = $this->validate([
            'claim_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'claim_date' => ['required', 'date', 'before_or_equal:today'],
            'claim_reference' => ['required', 'string', 'max:255'],
            'claim_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'claim_amount.regex' => 'Enter an amount like 1500.00.',
            'claim_date.before_or_equal' => 'The transfer date cannot be in the future.',
            'claim_reference.required' => 'Enter the bank transfer reference so the council can find your payment.',
        ]);

        try {
            $claims->submit(
                $tenant,
                Money::fromRufiyaa($validated['claim_amount']),
                CarbonImmutable::parse($validated['claim_date']),
                $validated['claim_reference'],
                $validated['claim_note'] ?: null,
            );
        } catch (InvalidTransferClaimException $e) {
            $this->addError('claim_amount', $e->getMessage());

            return;
        }

        $this->reset('claim_amount', 'claim_date', 'claim_reference', 'claim_note');
        session()->flash('portal_status', 'Thank you — the council will confirm your transfer and update your balance.');
    }

    public function logout(): void
    {
        Auth::guard('tenant')->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(route('portal.login'));
    }

    public function render(TenantLedger $ledger): View
    {
        $tenant = $this->tenant();

        $leases = $tenant->leases()
            ->with('property')
            ->orderByDesc('id')
            ->get();

        $invoices = Invoice::query()
            ->whereHas('lease', fn ($query) => $query->where('tenant_id', $tenant->id))
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->with('lease.property')
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get();

        $receipts = Receipt::query()
            ->where('tenant_id', $tenant->id)
            ->with('payments.invoice')
            ->orderByDesc('id')
            ->get();

        $entries = $this->tab === 'statement' ? $ledger->entries($tenant) : collect();

        return view('livewire.portal.home', [
            'tenant' => $tenant,
            'balance' => $tenant->outstandingBalance(),
            'outstandingCount' => $tenant->outstandingInvoiceCount(),
            'leases' => $leases,
            'invoices' => $invoices,
            'receipts' => $receipts,
            'entries' => $entries,
            'entriesBalance' => $entries->isEmpty() ? null : $ledger->balance($entries),
            'unsettledStatuses' => [InvoiceStatus::Issued, InvoiceStatus::PartlyPaid, InvoiceStatus::Overdue],
            'claims' => $tenant->transferClaims()->latest('id')->take(10)->get(),
            'pendingClaim' => $tenant->transferClaims()->pending()->first(),
        ]);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Auth::guard('tenant')->user();

        return $tenant;
    }
}
