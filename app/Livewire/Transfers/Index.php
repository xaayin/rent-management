<?php

declare(strict_types=1);

namespace App\Livewire\Transfers;

use App\Enums\Permission;
use App\Enums\TransferClaimStatus;
use App\Exceptions\InvalidTransferClaimException;
use App\Models\TransferClaim;
use App\Models\User;
use App\Services\Portal\TransferClaimService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Finance queue of tenant bank-transfer claims (T3). Whoever may record a
 * payment (§6.1: Finance + Supervisor) may confirm a claim — confirming is
 * just recording a payment the tenant asserted.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    /** pending | history */
    #[Url]
    public string $tab = 'pending';

    /** The claim being rejected — rejection needs a reason. */
    public ?int $rejectingId = null;

    public string $decision_note = '';

    public function mount(): void
    {
        Gate::authorize(Permission::RecordPayments->value);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->closeOverlays();
    }

    public function closeOverlays(): void
    {
        $this->reset('rejectingId', 'decision_note');
        $this->resetValidation();
    }

    public function confirm(int $id, TransferClaimService $claims): void
    {
        Gate::authorize(Permission::RecordPayments->value);

        $claim = TransferClaim::findOrFail($id);

        try {
            $receipt = $claims->confirm($claim, $this->currentUser());
        } catch (InvalidTransferClaimException $e) {
            session()->flash('status', $e->getMessage());
            $this->refreshPage();

            return;
        }

        session()->flash('status', "Confirmed · receipt {$receipt->number} issued for {$receipt->total()->format()}.");
        $this->refreshPage();
    }

    public function startReject(int $id): void
    {
        Gate::authorize(Permission::RecordPayments->value);

        $this->rejectingId = TransferClaim::findOrFail($id)->id;
        $this->decision_note = '';
    }

    public function confirmReject(TransferClaimService $claims): void
    {
        Gate::authorize(Permission::RecordPayments->value);

        $claim = TransferClaim::findOrFail($this->rejectingId);

        $this->validate([
            'decision_note' => ['required', 'string', 'max:2000'],
        ], [
            'decision_note.required' => 'Give the tenant a reason — they will see it.',
        ]);

        try {
            $claims->reject($claim, $this->currentUser(), $this->decision_note);
        } catch (InvalidTransferClaimException $e) {
            $this->addError('decision_note', $e->getMessage());

            return;
        }

        session()->flash('status', 'Transfer claim rejected — the tenant will see your reason.');
        $this->refreshPage();
    }

    public function render(): View
    {
        $claims = TransferClaim::query()
            ->with(['tenant', 'decider', 'receipt'])
            ->when(
                $this->tab === 'pending',
                fn ($q) => $q->pending(),
                fn ($q) => $q->where('status', '!=', TransferClaimStatus::Pending->value),
            )
            ->latest('id')
            ->paginate(10);

        return view('livewire.transfers.index', [
            'claims' => $claims,
            'pendingCount' => app(TransferClaimService::class)->pendingCount(),
        ]);
    }

    /**
     * The Transfers badge renders in the app layout on every page, so a full
     * round trip after a decision keeps the sidebar count honest (Livewire
     * re-renders only this component).
     */
    private function refreshPage(): void
    {
        $this->redirect(route('transfers.index', ['tab' => $this->tab]));
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
