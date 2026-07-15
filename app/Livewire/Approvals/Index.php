<?php

declare(strict_types=1);

namespace App\Livewire\Approvals;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Exceptions\InvalidApprovalException;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The supervisor's approvals inbox for the PRD §6.1 `A` cells.
 *
 * Only lists requests this user could have performed directly themselves — the
 * matrix stays the source of truth, this screen just routes the decision.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    /** pending | history */
    #[Url]
    public string $tab = 'pending';

    /** The request being rejected — a rejection must carry a reason. */
    public ?int $rejectingId = null;

    public string $decision_note = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ApprovalRequest::class);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->closeOverlays();
    }

    public function closeOverlays(): void
    {
        $this->reset('rejectingId', 'decision_note');
        $this->resetErrorBag();
    }

    public function approve(int $id): void
    {
        $request = ApprovalRequest::findOrFail($id);
        $this->authorize('decide', $request);

        try {
            app(ApprovalService::class)->approve($request, $this->currentUser());
        } catch (InvalidApprovalException $e) {
            // Typically the subject moved on while this sat in the queue.
            session()->flash('status', $e->getMessage());
            $this->refreshPage();

            return;
        }

        session()->flash('status', $request->action->label().' approved.');
        $this->refreshPage();
    }

    public function startReject(int $id): void
    {
        $request = ApprovalRequest::findOrFail($id);
        $this->authorize('decide', $request);

        $this->rejectingId = $request->id;
        $this->decision_note = '';
    }

    public function confirmReject(): void
    {
        $request = ApprovalRequest::findOrFail($this->rejectingId);
        $this->authorize('decide', $request);

        $this->validate([
            'decision_note' => ['required', 'string', 'max:2000'],
        ]);

        try {
            app(ApprovalService::class)->reject($request, $this->currentUser(), $this->decision_note);
        } catch (InvalidApprovalException $e) {
            $this->addError('decision_note', $e->getMessage());

            return;
        }

        $this->closeOverlays();
        session()->flash('status', 'Request rejected — the requester will see your reason.');
        $this->refreshPage();
    }

    /**
     * Reload the page after a decision.
     *
     * The pending count also renders as a badge on the sidebar, which lives in
     * the layout — Livewire re-renders only this component, so deciding would
     * empty the list while the badge kept its old number. A full round trip is
     * cheap here (a decision is rare and deliberate) and keeps the two honest.
     */
    private function refreshPage(): void
    {
        $this->redirect(route('approvals.index', ['tab' => $this->tab]));
    }

    public function render(): View
    {
        $service = app(ApprovalService::class);
        $actions = array_map(
            fn (ApprovalAction $a) => $a->value,
            $service->decidableActions($this->currentUser()),
        );

        $requests = ApprovalRequest::query()
            ->with(['requester', 'decider', 'subject'])
            ->whereIn('action', $actions)
            ->when(
                $this->tab === 'pending',
                fn ($q) => $q->pending(),
                fn ($q) => $q->where('status', '!=', ApprovalStatus::Pending->value),
            )
            ->latest('requested_at')
            ->orderByDesc('id')
            ->paginate(10);

        return view('livewire.approvals.index', [
            'requests' => $requests,
            'pendingCount' => $service->pendingCountFor($this->currentUser()),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
