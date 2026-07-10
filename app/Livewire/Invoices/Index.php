<?php

declare(strict_types=1);

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\TenantType;
use App\Enums\UsageType;
use App\Exceptions\InvalidPaymentException;
use App\Jobs\GenerateInvoices;
use App\Livewire\Concerns\InteractsWithPayments;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\PaymentRecorder;
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
    use InteractsWithPayments, WithPagination;

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

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
        $this->period = CarbonImmutable::now(config('app.timezone'))->format('Y-m');
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
            'methods' => PaymentMethod::cases(),
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
