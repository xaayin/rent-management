<?php

declare(strict_types=1);

namespace App\Livewire\Invoices;

use App\Enums\NotificationStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Exceptions\InvalidPaymentException;
use App\Jobs\GenerateInvoices;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceFineApplier;
use App\Services\Billing\PaymentRecorder;
use App\Services\Reminders\ReminderDispatcher;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    public string $period = '';

    // Record-payment panel (FR-PAY-01; design PRD §5.8 live allocation).
    public ?int $payingInvoiceId = null;

    public string $pay_amount = '';

    public string $pay_date = '';

    public string $pay_method = 'cash';

    public string $pay_reference = '';

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

    public function startPayment(int $invoiceId): void
    {
        $this->authorize('create', Payment::class);

        $this->resetPaymentForm();
        $this->payingInvoiceId = Invoice::findOrFail($invoiceId)->id;
        $this->pay_date = today()->toDateString();
    }

    public function confirmPayment(): void
    {
        $invoice = Invoice::findOrFail($this->payingInvoiceId);
        $this->authorize('create', Payment::class);

        $validated = $this->validate([
            'pay_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'pay_date' => ['required', 'date'],
            'pay_method' => ['required', Rule::enum(PaymentMethod::class)],
            'pay_reference' => ['nullable', 'string', 'max:255'],
        ], [
            'pay_amount.regex' => 'Enter an amount like 500.00.',
        ]);

        try {
            $payment = app(PaymentRecorder::class)->record(
                $invoice,
                Money::fromRufiyaa($validated['pay_amount']),
                CarbonImmutable::parse($validated['pay_date']),
                PaymentMethod::from($validated['pay_method']),
                $validated['pay_reference'] !== '' ? $validated['pay_reference'] : null,
                $this->currentUser(),
            );
        } catch (InvalidPaymentException $e) {
            $this->addError('pay_amount', $e->getMessage());

            return;
        }

        $this->resetPaymentForm();
        session()->flash('status', "Payment recorded · receipt {$payment->receipt_number} issued.");
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

    public function cancelPayment(): void
    {
        $this->resetPaymentForm();
    }

    /**
     * The one-click "send reminder now" action (FR-NOT-08).
     */
    public function sendReminder(int $invoiceId): void
    {
        Gate::authorize(Permission::IssueInvoices->value);

        $invoice = Invoice::with('lease.tenant')->findOrFail($invoiceId);

        $log = app(ReminderDispatcher::class)->sendManual($invoice);

        if ($log === null) {
            session()->flash('status', 'Reminder not sent — the tenant has opted out of SMS or the invoice is settled.');

            return;
        }

        session()->flash('status', $log->status === NotificationStatus::Sent
            ? "Reminder sent to {$log->recipient}."
            : 'Reminder attempt failed — see the notification log.');
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

    /**
     * The live preview for the open payment panel: outstanding amounts with
     * the fine recomputed for the chosen payment date, plus how the entered
     * amount would allocate (design PRD §5.8, FR-PAY-02).
     *
     * @return array<string, mixed>|null
     */
    private function buildPaymentPreview(): ?array
    {
        if ($this->payingInvoiceId === null) {
            return null;
        }

        $invoice = Invoice::with(['payments' => fn ($query) => $query->orderBy('id'), 'lease'])
            ->find($this->payingInvoiceId);

        if ($invoice === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($this->pay_date !== '' ? $this->pay_date : today()->toDateString());
        } catch (\Throwable) {
            $date = CarbonImmutable::parse(today()->toDateString());
        }

        // While principal is outstanding the fine tracks the payment date;
        // once settled it is frozen at the invoice's stored value.
        $fineLaari = $invoice->fine_laari;

        if ($invoice->outstandingPrincipalLaari() > 0) {
            $breakdown = app(InvoiceFineApplier::class)->previewFine($invoice, $date);
            $fineLaari = $breakdown?->totalLaari ?? 0;
        }

        $outstandingPrincipal = max($invoice->outstandingPrincipalLaari(), 0);
        $outstandingFine = max($fineLaari - $invoice->paidFineLaari(), 0);

        $allocation = null;

        if (preg_match('/^\d+(\.\d{1,2})?$/', $this->pay_amount) === 1) {
            $amount = Money::fromRufiyaa($this->pay_amount)->laari;
            $principal = min($amount, $outstandingPrincipal);
            $fine = min($amount - $principal, $outstandingFine);

            $allocation = [
                'principal' => Money::fromLaari($principal),
                'fine' => Money::fromLaari($fine),
                'excess' => $amount - $principal - $fine,
            ];
        }

        return [
            'invoice' => $invoice,
            'outstanding_principal' => Money::fromLaari($outstandingPrincipal),
            'outstanding_fine' => Money::fromLaari($outstandingFine),
            'outstanding_total' => Money::fromLaari($outstandingPrincipal + $outstandingFine),
            'allocation' => $allocation,
        ];
    }

    private function resetPaymentForm(): void
    {
        $this->reset(
            'payingInvoiceId', 'pay_amount', 'pay_date', 'pay_method', 'pay_reference',
            'reversingPaymentId', 'reversal_reason',
        );
        $this->resetValidation();
    }

    private function currentUser(): ?User
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user;
    }
}
