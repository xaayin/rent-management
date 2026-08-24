<?php

declare(strict_types=1);

namespace App\Livewire\Leases;

use App\Enums\ApprovalAction;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Livewire\Concerns\InteractsWithPayments;
use App\Models\ApprovalRequest;
use App\Models\Lease;
use App\Models\Payment;
use App\Services\Collections\ArrearsFollowUpService;
use App\Services\Reporting\LeaseAccountSummary;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * The lease workspace (design PRD §4.2, promoted from the list slide-over to a
 * page of its own): one URL per lease, so it can be bookmarked, opened in a
 * second tab during a phone call and linked to from the follow-up queue.
 *
 * Layout is the standard detail-page shape — an identity header, a money band
 * answering "what is owed right now", a main column carrying the money's
 * history, and a reference rail. The record-payment overlay comes from the same
 * trait the Invoices screen uses, so there is one payment surface, not two.
 */
#[Layout('components.layouts.app')]
class Show extends Component
{
    use InteractsWithPayments;

    public Lease $lease;

    /** How many history rows are shown before "Show more". */
    public int $historyLimit = 8;

    public function mount(Lease $lease): void
    {
        $this->authorize('viewAny', Lease::class);

        $this->lease = $lease->load(['property', 'tenant']);
    }

    public function showMoreHistory(): void
    {
        $this->historyLimit += 12;
    }

    public function closeOverlays(): void
    {
        $this->resetPaymentForm();
    }

    public function render(
        LeaseAccountSummary $summaries,
        ArrearsFollowUpService $followUps,
    ): View {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $lease = $this->lease->fresh(['property', 'tenant']);

        return view('livewire.leases.show', [
            'lease' => $lease,
            'summary' => $summaries->for($lease, $today),
            // Same engine as the money band, so the rail can never show a
            // tenant-wide balance smaller than this one lease's due.
            'tenantBalance' => $lease->tenant !== null
                ? $summaries->forTenant($lease->tenant, $today)
                : Money::fromLaari(0),
            'rule' => $lease->currentFineRule(),
            'invoiceHistory' => $lease->invoices()
                ->withSum('payments as paid_laari', 'amount_laari')
                ->orderByDesc('period_start')->orderByDesc('id')
                ->limit($this->historyLimit)
                ->get(),
            'invoiceTotal' => $lease->invoices()->count(),
            'payments' => Payment::query()
                ->whereHas('invoice', fn ($query) => $query->where('lease_id', $lease->id))
                ->with(['invoice', 'receipt'])
                ->orderByDesc('payment_date')->orderByDesc('id')
                ->limit(10)
                ->get(),
            'lastContact' => $lease->tenant?->arrearsContacts()
                ->with('recordedBy')->orderByDesc('contacted_on')->orderByDesc('id')->first(),
            'followUpOutcome' => ($contact = $lease->tenant?->arrearsContacts()
                ->orderByDesc('contacted_on')->orderByDesc('id')->first()) !== null
                    ? $followUps->outcomeFor($contact, $today)
                    : null,
            'terminationRequest' => ApprovalRequest::query()
                ->where('action', ApprovalAction::TerminateLease->value)
                ->where('subject_type', $lease->getMorphClass())
                ->where('subject_id', $lease->id)
                ->with('decider')
                ->latest('requested_at')->orderByDesc('id')
                ->first(),
            'activity' => Activity::query()
                ->where('subject_type', Lease::class)
                ->where('subject_id', $lease->id)
                ->latest()->take(8)->get(),
            'paying' => $this->buildPaymentPreview(),
            'methods' => PaymentMethod::cases(),
            'unpaidStatuses' => [InvoiceStatus::Issued, InvoiceStatus::PartlyPaid, InvoiceStatus::Overdue],
            'today' => $today,
        ]);
    }
}
