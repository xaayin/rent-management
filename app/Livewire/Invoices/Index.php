<?php

declare(strict_types=1);

namespace App\Livewire\Invoices;

use App\Enums\ApprovalAction;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\PaymentMethod;
use App\Enums\TenantType;
use App\Enums\UsageType;
use App\Exceptions\InvalidApprovalException;
use App\Exceptions\InvalidInvoiceRangeException;
use App\Exceptions\InvalidPaymentException;
use App\Jobs\GenerateInvoices;
use App\Livewire\Concerns\CollectsTenantPayments;
use App\Livewire\Concerns\InteractsWithPayments;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Services\Approvals\ApprovalService;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use CollectsTenantPayments, InteractsWithPayments, WithPagination;

    public string $period = '';

    // Filter toolbar (design PRD §5.5).
    #[Url]
    public string $q = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $tenantTypeFilter = '';

    #[Url]
    public string $propertyTypeFilter = '';

    /** Billing period filter, as YYYY-MM. */
    #[Url]
    public string $periodFilter = '';

    // Reversal flow (FR-PAY-06).
    public ?int $reversingPaymentId = null;

    public string $reversal_reason = '';

    // New-invoice modal (advance billing, FR-INV-05).
    public bool $creatingInvoice = false;

    public ?int $inv_lease_id = null;

    public string $inv_start = '';

    public int $inv_months = 1;

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
        $this->period = CarbonImmutable::now(config('app.timezone'))->format('Y-m');

        // The lease workspace deep-links here with the lease preselected.
        if (($leaseId = (int) request()->query('createFor')) > 0) {
            $this->openCreateInvoice($leaseId);
        }
    }

    public function openCreateInvoice(?int $leaseId = null): void
    {
        $this->authorize('generate', Invoice::class);

        $this->reset('inv_lease_id', 'inv_start', 'inv_months');
        $this->resetValidation();
        $this->creatingInvoice = true;

        if ($leaseId !== null && ($lease = Lease::find($leaseId)) !== null) {
            $this->inv_lease_id = $lease->id;
            $this->inv_start = $this->suggestedStart($lease);
        }
    }

    public function closeCreateInvoice(): void
    {
        $this->creatingInvoice = false;
        $this->reset('inv_lease_id', 'inv_start', 'inv_months');
        $this->resetValidation();
    }

    /**
     * Esc closes whichever overlay is on top (design PRD §5.7/§5.8).
     */
    public function closeOverlays(): void
    {
        if ($this->collecting) {
            $this->cancelCollect();

            return;
        }

        if ($this->creatingInvoice) {
            $this->closeCreateInvoice();

            return;
        }

        $this->cancelPayment();
    }

    public function updatedInvLeaseId(): void
    {
        $this->inv_months = 1;

        if (($lease = Lease::find($this->inv_lease_id)) !== null) {
            $this->inv_start = $this->suggestedStart($lease);
        }
    }

    public function setMonths(int $months): void
    {
        $this->inv_months = max(1, $months);
    }

    /**
     * Quick-pick: bill everything from the start month to the lease end.
     */
    public function setMonthsUntilLeaseEnd(): void
    {
        $lease = Lease::find($this->inv_lease_id);

        if ($lease === null || preg_match('/^\d{4}-\d{2}$/', $this->inv_start) !== 1) {
            return;
        }

        $from = CarbonImmutable::parse($this->inv_start.'-01');
        $months = (int) $from->diffInMonths(CarbonImmutable::parse($lease->expiry_date)->startOfMonth());

        $this->inv_months = max(1, $months);
    }

    public function createInvoice(): void
    {
        $this->authorize('generate', Invoice::class);

        $validated = $this->validate([
            'inv_lease_id' => ['required', 'integer', 'exists:leases,id'],
            'inv_start' => ['required', 'date_format:Y-m'],
            'inv_months' => ['required', 'integer', 'min:1', 'max:360'],
        ], [
            'inv_lease_id.required' => 'Pick the lease to invoice.',
        ]);

        $lease = Lease::findOrFail($validated['inv_lease_id']);

        try {
            $invoice = app(InvoiceGenerator::class)->generateRange(
                $lease,
                CarbonImmutable::parse($validated['inv_start'].'-01'),
                (int) $validated['inv_months'],
            );
        } catch (InvalidInvoiceRangeException $e) {
            $this->addError('inv_months', $e->getMessage());

            return;
        }

        $this->closeCreateInvoice();
        session()->flash('status', "Invoice {$invoice->number} created · {$invoice->total()->format()} covering {$invoice->periodLabel()}.");
    }

    /**
     * The month after the lease's last billed period — or its first billable
     * month — so the modal opens with a sensible default.
     */
    private function suggestedStart(Lease $lease): string
    {
        $lastBilled = $lease->invoices()->orderByDesc('period_end')->first();

        if ($lastBilled !== null) {
            return CarbonImmutable::parse($lastBilled->period_end->toDateString())->addDay()->format('Y-m');
        }

        $candidate = $lease->effectiveRentStart()->startOfMonth();
        $current = CarbonImmutable::now(config('app.timezone'))->startOfMonth();

        return $candidate->greaterThan($current) ? $candidate->format('Y-m') : $current->format('Y-m');
    }

    /**
     * Live preview for the new-invoice modal: totals, CSR occurrences, due
     * date — and any range conflict, surfaced before submitting.
     *
     * @return array<string, mixed>|null
     */
    private function buildInvoicePreview(): ?array
    {
        if (! $this->creatingInvoice
            || $this->inv_lease_id === null
            || preg_match('/^\d{4}-\d{2}$/', $this->inv_start) !== 1
            || $this->inv_months < 1) {
            return null;
        }

        $lease = Lease::with(['tenant', 'property'])->find($this->inv_lease_id);

        if ($lease === null) {
            return null;
        }

        $from = CarbonImmutable::parse($this->inv_start.'-01');
        $months = min($this->inv_months, 360);

        $error = null;

        try {
            app(InvoiceGenerator::class)->assertRangeBillable($lease, $from, $months);
        } catch (InvalidInvoiceRangeException $e) {
            $error = $e->getMessage();
        }

        $rentLaari = $lease->monthlyRent()->laari * $months;
        $csrOccurrences = 0;

        if ($lease->hasCsr()) {
            for ($offset = 0; $offset < $months; $offset++) {
                if ($from->addMonths($offset)->month === $lease->effectiveCsrMonth()) {
                    $csrOccurrences++;
                }
            }
        }

        $chargesLaari = $csrOccurrences * $lease->csrAnnualAmount()->laari;
        $lastMonth = $from->addMonths($months - 1);

        return [
            'lease' => $lease,
            'label' => $months === 1
                ? $from->format('M Y')
                : $from->format('M Y').' – '.$lastMonth->format('M Y'),
            'months' => $months,
            'monthly_rent' => $lease->monthlyRent(),
            'rent' => Money::fromLaari($rentLaari),
            'csr_occurrences' => $csrOccurrences,
            'charges' => Money::fromLaari($chargesLaari),
            'total' => Money::fromLaari($rentLaari + $chargesLaari),
            'due_date' => $from->day(min((int) $lease->due_day, $from->daysInMonth))->format('j M Y'),
            'error' => $error,
        ];
    }

    public function generate(): void
    {
        $this->authorize('generate', Invoice::class);

        $this->validate([
            'period' => ['required', 'date_format:Y-m'],
        ]);

        GenerateInvoices::dispatchSync($this->period);

        session()->flash('status', "Invoice run complete for {$this->period}.");
    }

    /**
     * Any filter change jumps back to page 1 so results never vanish behind a
     * stale page number.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'statusFilter', 'tenantTypeFilter', 'propertyTypeFilter', 'periodFilter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('q', 'statusFilter', 'tenantTypeFilter', 'propertyTypeFilter', 'periodFilter');
        $this->resetPage();
    }

    public function startReverse(int $paymentId): void
    {
        $payment = Payment::findOrFail($paymentId);
        $this->authorize('reverse', $payment);

        $this->reversingPaymentId = $payment->id;
        $this->reversal_reason = '';
    }

    public function confirmReverse(): void
    {
        $payment = Payment::findOrFail($this->reversingPaymentId);
        $this->authorize('reverse', $payment);

        $this->validate([
            'reversal_reason' => ['required', 'string', 'max:2000'],
        ]);

        /*
         * §6.1 marks "reverse a payment" `A` for Finance Officers: they may
         * start it, but a supervisor decides. Supervisors hold the "without
         * approval" variant and reverse immediately, exactly as before.
         */
        if (! $this->currentUser()->can('reverseDirectly', $payment)) {
            try {
                app(ApprovalService::class)->request(
                    $this->currentUser(),
                    ApprovalAction::ReversePayment,
                    $payment,
                    $this->reversal_reason,
                );
            } catch (InvalidApprovalException $e) {
                $this->addError('reversal_reason', $e->getMessage());

                return;
            }

            $this->reset('reversingPaymentId', 'reversal_reason');
            session()->flash('status', "Reversal of receipt {$payment->receipt_number} sent to a supervisor for approval.");

            return;
        }

        try {
            app(PaymentRecorder::class)->reverse(
                $payment,
                $this->reversal_reason,
                CarbonImmutable::parse(today()->toDateString()),
                $this->currentUser(),
            );
        } catch (InvalidPaymentException $e) {
            $this->addError('reversal_reason', $e->getMessage());

            return;
        }

        $this->reset('reversingPaymentId', 'reversal_reason');
        session()->flash('status', "Payment {$payment->receipt_number} reversed.");
    }

    /**
     * Closing the payment panel also abandons any reversal in progress.
     */
    public function cancelPayment(): void
    {
        $this->resetPaymentForm();
        $this->reset('reversingPaymentId', 'reversal_reason');
    }

    public function render(): View
    {
        return view('livewire.invoices.index', [
            'invoices' => $this->invoiceList(),
            'paying' => $this->buildPaymentPreview(),
            'newInvoice' => $this->buildInvoicePreview(),
            'activeLeases' => $this->creatingInvoice
                ? Lease::with(['tenant', 'property'])
                    ->where('status', LeaseStatus::Active->value)
                    ->orderBy('agreement_number')
                    ->get()
                : collect(),
            'methods' => PaymentMethod::cases(),
            'collectPreview' => $this->buildCollectPreview(),
            'invoiceStatuses' => InvoiceStatus::cases(),
            'tenantTypes' => TenantType::cases(),
            'usageTypes' => UsageType::cases(),
        ]);
    }

    /**
     * The filtered, paginated invoice list (design PRD §5.4/§5.5).
     *
     * @return LengthAwarePaginator<int, Invoice>
     */
    private function invoiceList(): LengthAwarePaginator
    {
        return Invoice::query()
            ->with(['lease.tenant', 'lease.property'])
            ->when($this->q !== '', function ($query): void {
                $term = '%'.$this->q.'%';
                $query->where(fn ($inner) => $inner
                    ->where('number', 'like', $term)
                    ->orWhereHas('lease.tenant', fn ($t) => $t->where('name', 'like', $term))
                    ->orWhereHas('lease.property', fn ($p) => $p->where('name', 'like', $term)
                        ->orWhere('land_number', 'like', $term))
                    ->orWhereHas('lease', fn ($l) => $l->where('agreement_number', 'like', $term)));
            })
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->tenantTypeFilter !== '', fn ($query) => $query->whereHas(
                'lease.tenant', fn ($t) => $t->where('type', $this->tenantTypeFilter),
            ))
            ->when($this->propertyTypeFilter !== '', fn ($query) => $query->whereHas(
                'lease.property', fn ($p) => $p->where('usage_type', $this->propertyTypeFilter),
            ))
            ->when(preg_match('/^\d{4}-\d{2}$/', $this->periodFilter) === 1, function ($query): void {
                [$year, $month] = explode('-', $this->periodFilter);
                $query->where('period_year', (int) $year)->where('period_month', (int) $month);
            })
            ->latest()
            ->orderByDesc('id') // deterministic tiebreak within one second
            ->paginate(10);
    }
}
