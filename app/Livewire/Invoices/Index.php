<?php

declare(strict_types=1);

namespace App\Livewire\Invoices;

use App\Enums\PaymentMethod;
use App\Exceptions\InvalidPaymentException;
use App\Jobs\GenerateInvoices;
use App\Livewire\Concerns\InteractsWithPayments;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\PaymentRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use InteractsWithPayments;

    public string $period = '';

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
            'invoices' => Invoice::with(['lease.tenant', 'lease.property'])
                ->latest()
                ->limit(100)
                ->get(),
            'paying' => $this->buildPaymentPreview(),
            'methods' => PaymentMethod::cases(),
        ]);
    }
}
