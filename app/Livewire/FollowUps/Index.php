<?php

declare(strict_types=1);

namespace App\Livewire\FollowUps;

use App\Enums\ContactChannel;
use App\Enums\Permission;
use App\Models\Tenant;
use App\Services\Collections\ArrearsFollowUpService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The arrears chasing worklist (R2): who to call today, ordered by urgency,
 * with the promise each call produced logged against the tenant.
 *
 * Gated on `record payments` — the people who collect the money are the people
 * who chase it.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    /** attention | promised | all */
    #[Url]
    public string $tab = 'attention';

    /** The tenant whose contact form is open. */
    public ?int $contactTenantId = null;

    public string $contact_channel = 'call';

    public string $contact_note = '';

    public string $contact_promised_on = '';

    public string $contact_promised_amount = '';

    /** The tenant whose history panel is open. */
    public ?int $historyTenantId = null;

    public function mount(): void
    {
        $this->authorize(Permission::RecordPayments->value);
    }

    public function startContact(int $tenantId): void
    {
        $this->authorize(Permission::RecordPayments->value);

        $this->resetContactForm();
        $this->contactTenantId = Tenant::findOrFail($tenantId)->id;
    }

    public function saveContact(): void
    {
        $this->authorize(Permission::RecordPayments->value);

        $tenant = Tenant::findOrFail($this->contactTenantId);

        $validated = $this->validate([
            'contact_channel' => ['required', 'string'],
            'contact_note' => ['required', 'string', 'max:2000'],
            'contact_promised_on' => ['nullable', 'date'],
            'contact_promised_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], [
            'contact_note.required' => 'Write what was said — that is the whole point of the log.',
            'contact_promised_amount.regex' => 'Enter an amount like 500.00.',
        ]);

        app(ArrearsFollowUpService::class)->log($tenant, [
            'contacted_on' => CarbonImmutable::parse(today()->toDateString())->toDateString(),
            'channel' => $validated['contact_channel'],
            'note' => $validated['contact_note'],
            'promised_on' => $validated['contact_promised_on'] ?? '',
            'promised_amount' => $validated['contact_promised_amount'] ?? '',
        ], auth()->user());

        $this->resetContactForm();
        session()->flash('status', 'Follow-up logged for '.$tenant->name.'.');
    }

    public function showHistory(int $tenantId): void
    {
        $this->authorize(Permission::RecordPayments->value);
        $this->historyTenantId = Tenant::findOrFail($tenantId)->id;
    }

    public function closeOverlays(): void
    {
        if ($this->contactTenantId !== null) {
            $this->resetContactForm();

            return;
        }

        $this->historyTenantId = null;
    }

    public function render(ArrearsFollowUpService $followUps): View
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $historyTenant = $this->historyTenantId !== null ? Tenant::find($this->historyTenantId) : null;

        return view('livewire.follow-ups.index', [
            'rows' => $followUps->queue($today, $this->tab),
            'summary' => $followUps->summary($today),
            'contactTenant' => $this->contactTenantId !== null ? Tenant::find($this->contactTenantId) : null,
            'historyTenant' => $historyTenant,
            'history' => $historyTenant !== null ? $followUps->historyFor($historyTenant) : collect(),
            'outcomes' => $historyTenant !== null
                ? $followUps->historyFor($historyTenant)
                    ->mapWithKeys(fn ($c) => [$c->id => $followUps->outcomeFor($c, $today)])
                : collect(),
            'channels' => ContactChannel::cases(),
            'today' => $today,
        ]);
    }

    private function resetContactForm(): void
    {
        $this->reset('contactTenantId', 'contact_channel', 'contact_note', 'contact_promised_on', 'contact_promised_amount');
        $this->resetValidation();
    }
}
