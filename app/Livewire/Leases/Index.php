<?php

declare(strict_types=1);

namespace App\Livewire\Leases;

use App\Enums\ApprovalAction;
use App\Enums\CsrBilling;
use App\Enums\CsrType;
use App\Enums\FineBase;
use App\Enums\FineMethod;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\PaymentMethod;
use App\Enums\RentBasis;
use App\Enums\TenantType;
use App\Enums\UsageType;
use App\Exceptions\InvalidApprovalException;
use App\Exceptions\InvalidFineRuleException;
use App\Livewire\Concerns\InteractsWithPayments;
use App\Models\ApprovalRequest;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Approvals\ApprovalService;
use App\Services\Billing\DueDateCalculator;
use App\Services\Billing\FineCalculator;
use App\Services\Billing\FineRuleScheduler;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;
use Throwable;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use InteractsWithPayments, WithPagination;

    /** Saved-view tab (design PRD §5.5): all | active | overdue | expiring. */
    #[Url]
    public string $tab = 'all';

    /** Free-text filter, also fed by the top-bar global search. */
    #[Url]
    public string $q = '';

    /** Filter chips (design PRD §5.5): status, tenant type, property type. */
    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $tenantTypeFilter = '';

    #[Url]
    public string $propertyTypeFilter = '';

    /** The lease open in the detail slide-over (design PRD §4.2). */
    public ?int $selectedId = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $agreement_number = '';

    public ?int $property_id = null;

    public ?int $tenant_id = null;

    public string $agreement_date = '';

    public string $start_date = '';

    public string $rent_start_date = '';

    public ?int $duration_years = null;

    public string $expiry_date = '';

    public string $rent_basis = '';

    public ?int $rate_laari = null;

    public ?int $area_sqft = null;

    public string $flat_amount = '';

    // Charge configuration (FR-CHG-03/04/05).
    public int $grace_months = 0;

    public int $due_day = 10;

    public string $csr_type = 'none';

    public string $csr_amount = '';

    public string $csr_percent = '';

    public string $csr_declared_revenue = '';

    public ?int $csr_month = null;

    /** with_rent | separate — how the annual CSR is invoiced (agreement term). */
    public string $csr_billing = 'with_rent';

    public string $status = 'draft';

    // Termination flow.
    public ?int $terminatingId = null;

    public string $termination_reason = '';

    // Fine-rule configuration (PRD §4.7; permission: configure fine rules).
    public ?int $fineRuleLeaseId = null;

    public string $fine_method = 'tiered_monthly';

    public string $fine_base = 'rent';

    public int $fine_allowance_days = 0;

    public string $fine_flat_amount = '';

    public string $fine_percent = '';

    public string $fine_first_month = '100';

    public string $fine_subsequent_month = '50';

    public string $fine_cap = '';

    public string $fine_effective_from = '';

    public string $fine_effective_to = '';

    /** Open end — the period runs until something supersedes it. */
    public bool $fine_ongoing = true;

    /** Whether the "add a period" form is expanded inside the schedule manager. */
    public bool $showFineForm = false;

    /** The period being edited in place; null means the form adds a new one. */
    public ?int $editingFineRuleId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Lease::class);

        // The top-bar Create menu deep-links here (design PRD §6.6).
        if (request()->boolean('create')) {
            $this->create();

            return;
        }

        // The lease page deep-links back for the two tasks that still live on
        // this screen: the edit form and the fine-schedule manager.
        if (($editId = (int) request()->query('edit')) > 0) {
            $this->edit($editId);

            return;
        }

        if (($fineId = (int) request()->query('fines')) > 0) {
            $this->configureFine($fineId);
        }
    }

    public function create(): void
    {
        $this->authorize('create', Lease::class);
        $this->resetForm();
        $this->selectedId = null;
        $this->showForm = true;
    }

    public function selectLease(int $id): void
    {
        $this->selectedId = Lease::findOrFail($id)->id;
    }

    /**
     * Any change to a filter jumps back to page 1 so results never vanish
     * behind a stale page number.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'tab', 'statusFilter', 'tenantTypeFilter', 'propertyTypeFilter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('q', 'statusFilter', 'tenantTypeFilter', 'propertyTypeFilter');
        $this->resetPage();
    }

    public function closeLease(): void
    {
        $this->selectedId = null;
        $this->resetPaymentForm();
        $this->reset('terminatingId', 'termination_reason');
        $this->resetFineForm();
    }

    /**
     * Esc closes whichever overlay is on top (design PRD §5.7/§5.8).
     */
    public function closeOverlays(): void
    {
        if ($this->fineRuleLeaseId !== null) {
            $this->closeFineSchedule();

            return;
        }

        if ($this->payingInvoiceId !== null) {
            $this->resetPaymentForm();

            return;
        }

        if ($this->showForm) {
            $this->cancel();

            return;
        }

        $this->closeLease();
    }

    public function edit(int $id): void
    {
        $lease = Lease::findOrFail($id);
        $this->authorize('update', $lease);

        $this->selectedId = null;
        $this->editingId = $lease->id;
        $this->agreement_number = $lease->agreement_number;
        $this->property_id = $lease->property_id;
        $this->tenant_id = $lease->tenant_id;
        $this->agreement_date = $lease->agreement_date->toDateString();
        $this->start_date = $lease->start_date->toDateString();
        $this->rent_start_date = $lease->rent_start_date->toDateString();
        $this->duration_years = $lease->duration_years;
        $this->expiry_date = $lease->expiry_date->toDateString();
        $this->rent_basis = $lease->rent_basis->value;
        $this->rate_laari = $lease->rate_laari;
        $this->area_sqft = $lease->area_sqft;
        $this->flat_amount = $lease->flat_amount_laari !== null
            ? Money::fromLaari($lease->flat_amount_laari)->toRufiyaa()
            : '';
        $this->grace_months = $lease->grace_months;
        $this->due_day = $lease->due_day;
        $this->csr_type = $lease->csr_type->value;
        $this->csr_amount = $lease->csr_amount_laari !== null ? Money::fromLaari($lease->csr_amount_laari)->toRufiyaa() : '';
        $this->csr_percent = $lease->csr_percent_bps !== null ? (string) ($lease->csr_percent_bps / 100) : '';
        $this->csr_declared_revenue = $lease->csr_declared_revenue_laari !== null ? Money::fromLaari($lease->csr_declared_revenue_laari)->toRufiyaa() : '';
        $this->csr_month = $lease->csr_month;
        $this->csr_billing = $lease->csr_billing->value;
        $this->status = $lease->status->value;
        $this->showForm = true;
    }

    public function updatedStartDate(): void
    {
        $this->syncExpiry();
    }

    public function updatedDurationYears(): void
    {
        $this->syncExpiry();
    }

    public function save(): void
    {
        $lease = $this->editingId ? Lease::findOrFail($this->editingId) : null;

        $this->authorize($lease ? 'update' : 'create', $lease ?? Lease::class);

        $isPerSqft = $this->rent_basis === RentBasis::PerSquareFoot->value;

        $validated = $this->validate([
            'agreement_number' => ['required', 'string', 'max:255', Rule::unique('leases', 'agreement_number')->ignore($this->editingId)],
            'property_id' => ['required', 'integer', Rule::exists('properties', 'id')],
            'tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')],
            'agreement_date' => ['required', 'date'],
            'start_date' => ['required', 'date'],
            'rent_start_date' => ['required', 'date', 'after_or_equal:start_date'],
            'duration_years' => ['required', 'integer', 'min:1', 'max:100'],
            'expiry_date' => ['required', 'date', 'after:start_date'],
            'rent_basis' => ['required', Rule::enum(RentBasis::class)],
            'rate_laari' => [Rule::requiredIf($isPerSqft), 'nullable', 'integer', 'min:1'],
            'area_sqft' => [Rule::requiredIf($isPerSqft), 'nullable', 'integer', 'min:1'],
            'flat_amount' => [Rule::requiredIf(! $isPerSqft), 'nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'grace_months' => ['required', 'integer', 'min:0', 'max:120'],
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'csr_type' => ['required', Rule::enum(CsrType::class)],
            'csr_amount' => [Rule::requiredIf($this->csr_type === CsrType::FixedAnnual->value), 'nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'csr_percent' => [Rule::requiredIf($this->csr_type === CsrType::PercentOfRevenue->value), 'nullable', 'numeric', 'min:0'],
            'csr_declared_revenue' => [Rule::requiredIf($this->csr_type === CsrType::PercentOfRevenue->value), 'nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'csr_month' => [Rule::requiredIf($this->csr_type !== CsrType::None->value), 'nullable', 'integer', 'min:1', 'max:12'],
            'csr_billing' => ['required', Rule::enum(CsrBilling::class)],
            'status' => ['required', Rule::in([LeaseStatus::Draft->value, LeaseStatus::Active->value])],
        ]);

        // FR-PRP-02: pre-check so the user sees a validation message rather than
        // the model-layer guard's exception.
        if ($validated['status'] === LeaseStatus::Active->value
            && $this->parcelHasActiveConflict((int) $validated['property_id'])) {
            $this->addError('property_id', 'This parcel already has an active lease.');

            return;
        }

        $attributes = [
            'agreement_number' => $validated['agreement_number'],
            'property_id' => $validated['property_id'],
            'tenant_id' => $validated['tenant_id'],
            'agreement_date' => $validated['agreement_date'],
            'start_date' => $validated['start_date'],
            'rent_start_date' => $validated['rent_start_date'],
            'duration_years' => $validated['duration_years'],
            'expiry_date' => $validated['expiry_date'],
            'rent_basis' => $validated['rent_basis'],
            'status' => $validated['status'],
            'rate_laari' => $isPerSqft ? $validated['rate_laari'] : null,
            'area_sqft' => $isPerSqft ? $validated['area_sqft'] : null,
            'flat_amount_laari' => $isPerSqft ? null : Money::fromRufiyaa($validated['flat_amount'])->laari,
            'grace_months' => $validated['grace_months'],
            'due_day' => $validated['due_day'],
            'csr_type' => $validated['csr_type'],
            'csr_amount_laari' => $this->csr_type === CsrType::FixedAnnual->value
                ? Money::fromRufiyaa($validated['csr_amount'])->laari : null,
            'csr_percent_bps' => $this->csr_type === CsrType::PercentOfRevenue->value
                ? (int) round(((float) $validated['csr_percent']) * 100) : null,
            'csr_declared_revenue_laari' => $this->csr_type === CsrType::PercentOfRevenue->value
                ? Money::fromRufiyaa($validated['csr_declared_revenue'])->laari : null,
            'csr_month' => $this->csr_type !== CsrType::None->value ? $validated['csr_month'] : null,
            'csr_billing' => $validated['csr_billing'],
        ];

        if ($lease) {
            $lease->update($attributes);
        } else {
            Lease::create($attributes);
        }

        $this->resetForm();
        $this->showForm = false;
        session()->flash('status', 'Lease saved.');
    }

    public function startTerminate(int $id): void
    {
        $lease = Lease::findOrFail($id);
        $this->authorize('terminate', $lease);

        $this->terminatingId = $lease->id;
        $this->termination_reason = '';
    }

    public function confirmTerminate(): void
    {
        $lease = Lease::findOrFail($this->terminatingId);
        $this->authorize('terminate', $lease);

        $this->validate([
            'termination_reason' => ['required', 'string', 'max:2000'],
        ]);

        /*
         * §6.1 marks "terminate a lease" `A` for Land Officers: they may start
         * it, but a supervisor decides. Supervisors hold the "without approval"
         * variant and act immediately, exactly as before.
         */
        if (! $this->currentUser()->can('terminateDirectly', $lease)) {
            try {
                app(ApprovalService::class)->request(
                    $this->currentUser(),
                    ApprovalAction::TerminateLease,
                    $lease,
                    $this->termination_reason,
                );
            } catch (InvalidApprovalException $e) {
                $this->addError('termination_reason', $e->getMessage());

                return;
            }

            $this->reset('terminatingId', 'termination_reason');
            session()->flash('status', 'Termination sent to a supervisor for approval.');

            return;
        }

        $lease->terminate($this->termination_reason);

        $this->reset('terminatingId', 'termination_reason');
        session()->flash('status', 'Lease terminated.');
    }

    /**
     * Withdraw a termination request the current user filed themselves.
     */
    public function withdrawTermination(int $requestId): void
    {
        $request = ApprovalRequest::findOrFail($requestId);
        $this->authorize('cancel', $request);

        try {
            app(ApprovalService::class)->cancel($request, $this->currentUser());
        } catch (InvalidApprovalException $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        session()->flash('status', 'Termination request withdrawn.');
    }

    public function configureFine(int $id): void
    {
        $lease = Lease::findOrFail($id);
        $this->authorize('configureFineRule', $lease);

        $this->resetFineForm();
        $this->fineRuleLeaseId = $lease->id;
        $this->showFineForm = true;
        $this->fine_effective_from = today()->toDateString();
        $this->fine_effective_to = '';
        $this->fine_ongoing = true;

        // Prefill from the rule currently in force so edits start from reality.
        if (($current = $lease->currentFineRule()) !== null) {
            $this->fine_method = $current->method->value;
            $this->fine_base = $current->base->value;
            $this->fine_allowance_days = $current->allowance_days;
            $this->fine_flat_amount = $current->flat_daily_laari !== null
                ? Money::fromLaari($current->flat_daily_laari)->toRufiyaa() : '';
            $this->fine_percent = $current->percent_daily_bps !== null
                ? rtrim(rtrim(number_format($current->percent_daily_bps / 100, 2, '.', ''), '0'), '.') : '';
            $this->fine_first_month = Money::fromLaari($current->firstMonthLaari())->toRufiyaa();
            $this->fine_subsequent_month = Money::fromLaari($current->subsequentMonthLaari())->toRufiyaa();
            $this->fine_cap = $current->cap_laari !== null
                ? Money::fromLaari($current->cap_laari)->toRufiyaa() : '';
        }
    }

    public function saveFineRule(): void
    {
        $lease = Lease::findOrFail($this->fineRuleLeaseId);
        $this->authorize('configureFineRule', $lease);

        $isFlat = $this->fine_method === FineMethod::FlatPerDay->value;
        $isPercent = $this->fine_method === FineMethod::PercentPerDay->value;
        $isTiered = $this->fine_method === FineMethod::TieredMonthly->value;

        $validated = $this->validate([
            'fine_method' => ['required', Rule::enum(FineMethod::class)],
            'fine_base' => ['required', Rule::enum(FineBase::class)],
            'fine_allowance_days' => ['required', 'integer', 'min:0', 'max:365'],
            'fine_flat_amount' => [Rule::requiredIf($isFlat), 'nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'fine_percent' => [Rule::requiredIf($isPercent), 'nullable', 'numeric', 'gt:0', 'max:100'],
            'fine_first_month' => [Rule::requiredIf($isTiered), 'nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'fine_subsequent_month' => [Rule::requiredIf($isTiered), 'nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'fine_cap' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'fine_effective_from' => ['required', 'date'],
            'fine_effective_to' => [
                Rule::requiredIf(! $this->fine_ongoing), 'nullable', 'date', 'after_or_equal:fine_effective_from',
            ],
        ]);

        // Each change is a new effective-dated PERIOD (FR-FIN-09), so historic
        // invoices keep the rule they were fined under. The scheduler owns the
        // no-overlap invariant and supersedes an open-ended predecessor.
        $attributes = [
            'method' => $validated['fine_method'],
            'base' => $validated['fine_base'],
            'allowance_days' => $validated['fine_allowance_days'],
            'flat_daily_laari' => $isFlat ? Money::fromRufiyaa($validated['fine_flat_amount'])->laari : null,
            'percent_daily_bps' => $isPercent ? (int) round(((float) $validated['fine_percent']) * 100) : null,
            'first_month_laari' => $isTiered ? Money::fromRufiyaa($validated['fine_first_month'])->laari : null,
            'subsequent_month_laari' => $isTiered ? Money::fromRufiyaa($validated['fine_subsequent_month'])->laari : null,
            'cap_laari' => $validated['fine_cap'] !== null && $validated['fine_cap'] !== ''
                ? Money::fromRufiyaa($validated['fine_cap'])->laari : null,
            'effective_from' => $validated['fine_effective_from'],
            'effective_to' => $this->fine_ongoing ? null : ($validated['fine_effective_to'] ?: null),
        ];

        $scheduler = app(FineRuleScheduler::class);

        try {
            if ($this->editingFineRuleId !== null) {
                $scheduler->update(FineRule::findOrFail($this->editingFineRuleId), $attributes);
            } else {
                $scheduler->schedule($lease, $attributes);
            }
        } catch (InvalidFineRuleException $e) {
            // The dates are what the user must change, so the message lands there.
            $this->addError('fine_effective_from', $e->getMessage());

            return;
        }

        $edited = $this->editingFineRuleId !== null;
        $this->cancelFineForm();
        session()->flash('status', $edited ? 'Fine period updated.' : 'Fine period saved.');
    }

    /**
     * End a running period today without replacing it — fines stop accruing on
     * invoices issued from tomorrow (FR-FIN-09).
     */
    public function endFinePeriod(int $ruleId): void
    {
        $rule = FineRule::findOrFail($ruleId);
        $this->authorize('configureFineRule', $rule->lease);

        try {
            app(FineRuleScheduler::class)->close($rule, CarbonImmutable::parse(today()->toDateString()));
        } catch (InvalidFineRuleException $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        session()->flash('status', 'Fine period ended '.today()->format('j M Y').'.');
    }

    /** Delete a period that never started and never fined anything. */
    public function removeFinePeriod(int $ruleId): void
    {
        $rule = FineRule::findOrFail($ruleId);
        $this->authorize('configureFineRule', $rule->lease);

        try {
            app(FineRuleScheduler::class)->remove($rule);
        } catch (InvalidFineRuleException $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        session()->flash('status', 'Scheduled fine period removed.');
    }

    /**
     * Edit a period in place. Only offered while nothing was invoiced under it;
     * the scheduler re-checks that on save, so the button is a convenience and
     * never the guarantee.
     */
    public function editFinePeriod(int $ruleId): void
    {
        $rule = FineRule::findOrFail($ruleId);
        $this->authorize('configureFineRule', $rule->lease);

        $this->resetValidation();
        $this->editingFineRuleId = $rule->id;
        $this->showFineForm = true;

        $this->fine_method = $rule->method->value;
        $this->fine_base = $rule->base->value;
        $this->fine_allowance_days = $rule->allowance_days;
        $this->fine_flat_amount = $rule->flat_daily_laari !== null
            ? Money::fromLaari($rule->flat_daily_laari)->toRufiyaa() : '';
        $this->fine_percent = $rule->percent_daily_bps !== null
            ? rtrim(rtrim(number_format($rule->percent_daily_bps / 100, 2, '.', ''), '0'), '.') : '';
        $this->fine_first_month = Money::fromLaari($rule->firstMonthLaari())->toRufiyaa();
        $this->fine_subsequent_month = Money::fromLaari($rule->subsequentMonthLaari())->toRufiyaa();
        $this->fine_cap = $rule->cap_laari !== null ? Money::fromLaari($rule->cap_laari)->toRufiyaa() : '';
        $this->fine_effective_from = $rule->effective_from->toDateString();
        $this->fine_effective_to = $rule->effective_to?->toDateString() ?? '';
        $this->fine_ongoing = $rule->isOpenEnded();
    }

    /** Leave edit mode without closing the whole schedule. */
    public function cancelFineForm(): void
    {
        $this->reset(
            'showFineForm', 'editingFineRuleId', 'fine_method', 'fine_base',
            'fine_allowance_days', 'fine_flat_amount', 'fine_percent',
            'fine_first_month', 'fine_subsequent_month', 'fine_cap',
            'fine_effective_from', 'fine_effective_to', 'fine_ongoing',
        );
        $this->resetValidation();
    }

    public function closeFineSchedule(): void
    {
        $this->resetFineForm();
        $this->showFineForm = false;
    }

    /** Typing an end date means the period is closed — keep the toggle honest. */
    public function updatedFineEffectiveTo(string $value): void
    {
        $this->fine_ongoing = $value === '';
    }

    /** Ticking "no end date" clears whatever end date was there. */
    public function updatedFineOngoing(bool $value): void
    {
        if ($value) {
            $this->fine_effective_to = '';
        }
    }

    /**
     * Quick period shapes, so the common cases are one click rather than two
     * date pickers: from today on, this calendar year, or the rest of the term.
     */
    public function applyFinePreset(string $preset): void
    {
        $lease = Lease::find($this->fineRuleLeaseId);
        $today = CarbonImmutable::parse(today()->toDateString());

        [$from, $to] = match ($preset) {
            'this_year' => [$today->startOfYear(), $today->endOfYear()],
            'rest_of_term' => [$today, $lease?->expiry_date
                ? CarbonImmutable::parse($lease->expiry_date->toDateString()) : null],
            default => [$today, null],
        };

        $this->fine_effective_from = $from->toDateString();
        $this->fine_effective_to = $to?->toDateString() ?? '';
        $this->fine_ongoing = $to === null;
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
        $this->reset('terminatingId', 'termination_reason');
        $this->resetFineForm();
    }

    public function render(): View
    {
        return view('livewire.leases.index', [
            'dueDateHint' => $this->dueDateHint(),
            'fineSchedule' => $this->fineSchedule(),
            'leases' => $this->leaseList(),
            'detail' => $this->leaseDetail(),
            'paying' => $this->buildPaymentPreview(),
            'properties' => Property::orderBy('name')->get(),
            'tenants' => Tenant::orderBy('name')->get(),
            'rentBases' => RentBasis::cases(),
            'csrTypes' => CsrType::cases(),
            'fineMethods' => FineMethod::cases(),
            'fineBases' => FineBase::cases(),
            'methods' => PaymentMethod::cases(),
            'leaseStatuses' => LeaseStatus::cases(),
            'tenantTypes' => TenantType::cases(),
            'usageTypes' => UsageType::cases(),
        ]);
    }

    /**
     * Everything the fine-schedule manager renders for the open lease: the
     * timeline of periods and no-fine gaps, what the period being typed would
     * do, a worked example on this lease's own rent, and the record of which
     * period actually fined which invoice.
     *
     * @return array<string, mixed>|null
     */
    private function fineSchedule(): ?array
    {
        $lease = $this->fineRuleLeaseId !== null
            ? Lease::with(['property', 'tenant'])->find($this->fineRuleLeaseId)
            : null;

        if ($lease === null) {
            return null;
        }

        $scheduler = app(FineRuleScheduler::class);
        $draft = $this->draftFineRule();

        return [
            'lease' => $lease,
            'timeline' => $scheduler->timeline($lease),
            'inspection' => $this->showFineForm && $this->fine_effective_from !== ''
                ? $scheduler->inspect(
                    $lease,
                    CarbonImmutable::parse($this->fine_effective_from),
                    $this->fine_ongoing || $this->fine_effective_to === ''
                        ? null : CarbonImmutable::parse($this->fine_effective_to),
                    $this->editingFineRuleId,
                )
                : null,
            'example' => $draft !== null ? $this->finePreviewExample($lease, $draft) : null,
            'history' => $this->fineHistory($lease),
        ];
    }

    /**
     * The period being typed, as an unsaved FineRule — enough for the calculator
     * to price it. Null while the form is closed or the amounts are incomplete.
     */
    private function draftFineRule(): ?FineRule
    {
        if (! $this->showFineForm) {
            return null;
        }

        $isFlat = $this->fine_method === FineMethod::FlatPerDay->value;
        $isPercent = $this->fine_method === FineMethod::PercentPerDay->value;

        try {
            if ($isFlat && ! preg_match('/^\d+(\.\d{1,2})?$/', $this->fine_flat_amount)) {
                return null;
            }
            if ($isPercent && ! is_numeric($this->fine_percent)) {
                return null;
            }

            return new FineRule([
                'method' => $this->fine_method,
                'base' => $this->fine_base,
                'allowance_days' => max($this->fine_allowance_days, 0),
                'flat_daily_laari' => $isFlat ? Money::fromRufiyaa($this->fine_flat_amount)->laari : null,
                'percent_daily_bps' => $isPercent ? (int) round(((float) $this->fine_percent) * 100) : null,
                'first_month_laari' => preg_match('/^\d+(\.\d{1,2})?$/', $this->fine_first_month)
                    ? Money::fromRufiyaa($this->fine_first_month)->laari : null,
                'subsequent_month_laari' => preg_match('/^\d+(\.\d{1,2})?$/', $this->fine_subsequent_month)
                    ? Money::fromRufiyaa($this->fine_subsequent_month)->laari : null,
                'cap_laari' => preg_match('/^\d+(\.\d{1,2})?$/', $this->fine_cap)
                    ? Money::fromRufiyaa($this->fine_cap)->laari : null,
                'effective_from' => $this->fine_effective_from !== '' ? $this->fine_effective_from : null,
                'effective_to' => $this->fine_ongoing || $this->fine_effective_to === ''
                    ? null : $this->fine_effective_to,
            ]);
        } catch (Throwable) {
            return null; // mid-typing — no example beats a wrong one
        }
    }

    /**
     * A worked example priced by the REAL calculator on this lease's own rent:
     * an invoice left exactly ten days late. What you see is what tenants get.
     *
     * @return array<string, mixed>|null
     */
    private function finePreviewExample(Lease $lease, FineRule $draft): ?array
    {
        try {
            $baseLaari = $draft->base === FineBase::RentPlusCharges
                ? $lease->monthlyRent()->laari + intdiv($lease->csrAnnualAmount()->laari, 12)
                : $lease->monthlyRent()->laari;

            $due = CarbonImmutable::parse(today()->toDateString())
                ->startOfMonth()->day(min($lease->due_day, 28));
            $asOf = $due->addDays($draft->allowance_days + 10);

            $breakdown = app(FineCalculator::class)->calculate(
                $draft, Money::fromLaari($baseLaari), $due, $asOf,
            );

            return [
                'base' => Money::fromLaari($baseLaari),
                'due' => $due,
                'as_of' => $asOf,
                'breakdown' => $breakdown,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Which period actually fined which invoice (FR-FIN-12). Read from the
     * invoice's recorded rule, falling back to resolving it from the issue date
     * for rows last fined before that column existed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fineHistory(Lease $lease): Collection
    {
        return $lease->invoices()
            ->with(['fineRule', 'lineItems'])
            ->where(fn ($q) => $q->where('fine_laari', '>', 0)->orWhereNotNull('fine_rule_id'))
            ->orderByDesc('due_date')->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(function (Invoice $invoice): array {
                $meta = $invoice->lineItems
                    ->firstWhere('type', InvoiceLineType::Fine)?->meta ?? [];

                return [
                    'invoice' => $invoice,
                    'rule' => $invoice->governingFineRule(),
                    'late_days' => $meta['late_days'] ?? null,
                    'still_accruing' => $invoice->outstandingPrincipalLaari() > 0,
                ];
            });
    }

    /**
     * A plain-language preview of when the lease's first invoice will fall due
     * under the configured anchoring rule (config/billing.php), computed by the
     * SAME calculator the generator uses — the hint can never promise a date
     * billing won't honour. Null while the form is empty or mid-typing.
     */
    private function dueDateHint(): ?string
    {
        if (! $this->showForm || $this->rent_start_date === '' || $this->due_day < 1 || $this->due_day > 31) {
            return null;
        }

        try {
            // An unsaved throwaway lease — just enough for effectiveRentStart().
            $preview = new Lease([
                'rent_start_date' => $this->rent_start_date,
                'grace_months' => max((int) $this->grace_months, 0),
                'due_day' => (int) $this->due_day,
            ]);

            $firstBillable = $preview->effectiveRentStart();
            $due = app(DueDateCalculator::class)->for($preview, $firstBillable->startOfMonth());

            // Explain whatever the configured rule actually decided, so the
            // wording stays honest if the council changes the anchor later.
            $why = $due->isSameMonth($firstBillable, true)
                ? ($firstBillable->day === 1
                    ? 'rent starts on the 1st, so invoices fall due within their own month'
                    : 'invoices fall due within their own month')
                : ($firstBillable->day === 1
                    ? 'invoices fall due the month after the one they bill'
                    : 'rent starts mid-month, so each invoice falls due the following month');

            return 'First invoice: '.$firstBillable->format('F Y')
                .' · due '.$due->format('j F Y').' — '.$why.'.';
        } catch (Throwable) {
            return null; // partial input mid-edit — no hint beats a wrong hint
        }
    }

    /**
     * The filtered issue-list rows with billing aggregates (design PRD §6.2),
     * paginated in the issue-list style (§5.4).
     *
     * @return LengthAwarePaginator<int, Lease>
     */
    private function leaseList(): LengthAwarePaginator
    {
        $unpaid = [InvoiceStatus::Issued->value, InvoiceStatus::PartlyPaid->value, InvoiceStatus::Overdue->value];

        return Lease::query()
            ->with(['property', 'tenant'])
            ->withCount([
                'invoices as overdue_invoices_count' => fn ($query) => $query->where('status', InvoiceStatus::Overdue->value),
            ])
            ->withSum('invoices as invoiced_laari', 'total_laari')
            ->withSum('payments as paid_laari', 'amount_laari')
            ->withMin([
                'invoices as next_due' => fn ($query) => $query->whereIn('status', $unpaid),
            ], 'due_date')
            ->withMin([
                'invoices as oldest_overdue_due' => fn ($query) => $query->where('status', InvoiceStatus::Overdue->value),
            ], 'due_date')
            ->when($this->q !== '', function ($query): void {
                $term = '%'.$this->q.'%';
                $query->where(fn ($inner) => $inner
                    ->where('agreement_number', 'like', $term)
                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', $term))
                    ->orWhereHas('property', fn ($p) => $p->where('name', 'like', $term)
                        ->orWhere('land_number', 'like', $term)));
            })
            ->when($this->tab === 'active', fn ($query) => $query->where('status', LeaseStatus::Active->value))
            ->when($this->tab === 'overdue', fn ($query) => $query->whereHas(
                'invoices', fn ($i) => $i->where('status', InvoiceStatus::Overdue->value),
            ))
            ->when($this->tab === 'expiring', fn ($query) => $query
                ->where('status', LeaseStatus::Active->value)
                ->whereBetween('expiry_date', [today()->toDateString(), today()->addDays(90)->toDateString()]))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->tenantTypeFilter !== '', fn ($query) => $query->whereHas(
                'tenant', fn ($t) => $t->where('type', $this->tenantTypeFilter),
            ))
            ->when($this->propertyTypeFilter !== '', fn ($query) => $query->whereHas(
                'property', fn ($p) => $p->where('usage_type', $this->propertyTypeFilter),
            ))
            ->latest()
            ->orderByDesc('id') // deterministic tiebreak within one second
            ->paginate(10);
    }

    /**
     * Everything the slide-over shows for the selected lease.
     *
     * @return array<string, mixed>|null
     */
    private function leaseDetail(): ?array
    {
        if ($this->selectedId === null) {
            return null;
        }

        $lease = Lease::with(['property', 'tenant'])->find($this->selectedId);

        if ($lease === null) {
            return null;
        }

        $unpaid = $lease->invoices()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartlyPaid->value, InvoiceStatus::Overdue->value])
            ->orderBy('due_date')
            ->get();

        $oldestOverdue = $unpaid->firstWhere('status', InvoiceStatus::Overdue);

        return [
            'lease' => $lease,
            'rule' => $lease->currentFineRule(),
            'unpaid' => $unpaid,
            'outstanding' => Money::fromLaari(max((int) $unpaid->sum(fn ($invoice) => $invoice->outstandingTotalLaari()), 0)),
            'overdue_days' => $oldestOverdue !== null
                ? (int) Carbon::parse($oldestOverdue->due_date)->diffInDays(today())
                : 0,
            'overdue_fine' => $oldestOverdue?->fine(),
            /*
             * The most recent termination request, if any. Shown so a Land
             * Officer sees their request is queued rather than filing it again,
             * and sees a supervisor's reason if it was turned down.
             */
            'termination_request' => ApprovalRequest::query()
                ->where('action', ApprovalAction::TerminateLease->value)
                ->where('subject_type', $lease->getMorphClass())
                ->where('subject_id', $lease->id)
                ->with('decider')
                ->latest('requested_at')
                ->orderByDesc('id')
                ->first(),
            'recent_invoices' => $lease->invoices()->orderByDesc('period_start')->take(6)->get(),
            'activity' => Activity::query()
                ->where('subject_type', Lease::class)
                ->where('subject_id', $lease->id)
                ->latest()
                ->take(5)
                ->get(),
        ];
    }

    private function syncExpiry(): void
    {
        if ($this->start_date !== '' && $this->duration_years) {
            $this->expiry_date = Carbon::parse($this->start_date)
                ->addYears($this->duration_years)
                ->toDateString();
        }
    }

    private function parcelHasActiveConflict(int $propertyId): bool
    {
        return Lease::query()
            ->where('property_id', $propertyId)
            ->where('status', LeaseStatus::Active->value)
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();
    }

    private function resetFineForm(): void
    {
        $this->reset(
            'fineRuleLeaseId', 'fine_method', 'fine_base', 'fine_allowance_days',
            'fine_flat_amount', 'fine_percent', 'fine_first_month',
            'fine_subsequent_month', 'fine_cap', 'fine_effective_from',
            'fine_effective_to', 'fine_ongoing', 'showFineForm',
        );
        $this->resetValidation();
    }

    private function resetForm(): void
    {
        $this->reset(
            'editingId', 'agreement_number', 'property_id', 'tenant_id',
            'agreement_date', 'start_date', 'rent_start_date', 'duration_years',
            'expiry_date', 'rent_basis', 'rate_laari', 'area_sqft', 'flat_amount',
            'grace_months', 'due_day', 'csr_type', 'csr_amount', 'csr_percent',
            'csr_declared_revenue', 'csr_month', 'csr_billing',
        );
        $this->status = 'draft';
        $this->resetValidation();
    }
}
