<?php

declare(strict_types=1);

namespace App\Livewire\Tenants;

use App\Enums\TenantType;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    /** Free-text filter: name, registry number, mobile or email. */
    #[Url]
    public string $q = '';

    /** Tenant-type chip (design PRD §5.5). */
    #[Url]
    public string $typeFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $type = '';

    public string $name = '';

    public string $national_id = '';

    public string $company_reg_no = '';

    public string $contact_person = '';

    public string $mobile = '';

    public string $email = '';

    public bool $sms_opt_out = false;

    public string $postal_address = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Tenant::class);

        // The top-bar Create menu deep-links here (design PRD §6.6).
        if (request()->boolean('create')) {
            $this->create();
        }
    }

    public function create(): void
    {
        $this->authorize('create', Tenant::class);
        $this->resetForm();
        $this->showForm = true;
    }

    /**
     * Any filter change jumps back to page 1 so results never vanish behind a
     * stale page number.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'typeFilter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('q', 'typeFilter');
        $this->resetPage();
    }

    public function edit(int $id): void
    {
        $tenant = Tenant::findOrFail($id);
        $this->authorize('update', $tenant);

        $this->editingId = $tenant->id;
        $this->type = $tenant->type->value;
        $this->name = $tenant->name;
        $this->national_id = (string) $tenant->national_id;
        $this->company_reg_no = (string) $tenant->company_reg_no;
        $this->contact_person = (string) $tenant->contact_person;
        $this->mobile = $tenant->mobile;
        $this->email = (string) $tenant->email;
        $this->sms_opt_out = $tenant->sms_opt_out;
        $this->postal_address = (string) $tenant->postal_address;
        $this->showForm = true;
    }

    public function save(): void
    {
        $tenant = $this->editingId ? Tenant::findOrFail($this->editingId) : null;

        $this->authorize($tenant ? 'update' : 'create', $tenant ?? Tenant::class);

        $isIndividual = $this->type === TenantType::Individual->value;

        $validated = $this->validate([
            'type' => ['required', Rule::enum(TenantType::class)],
            'name' => ['required', 'string', 'max:255'],
            'national_id' => [
                Rule::requiredIf($isIndividual), 'nullable', 'string', 'max:255',
                Rule::unique('tenants', 'national_id')->ignore($this->editingId),
            ],
            'company_reg_no' => [
                Rule::requiredIf(! $isIndividual), 'nullable', 'string', 'max:255',
                Rule::unique('tenants', 'company_reg_no')->ignore($this->editingId),
            ],
            'contact_person' => [Rule::requiredIf(! $isIndividual), 'nullable', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'sms_opt_out' => ['boolean'],
            'postal_address' => ['nullable', 'string', 'max:2000'],
        ], [
            'mobile.regex' => 'Enter a valid mobile number.',
        ]);

        // Clear the identifiers that do not apply to the chosen tenant type.
        $validated['national_id'] = $isIndividual ? ($validated['national_id'] ?? null) : null;
        $validated['company_reg_no'] = $isIndividual ? null : ($validated['company_reg_no'] ?? null);
        $validated['contact_person'] = $isIndividual ? null : ($validated['contact_person'] ?? null);

        if ($tenant) {
            $tenant->update($validated);
        } else {
            Tenant::create($validated);
        }

        $this->resetForm();
        $this->showForm = false;
        session()->flash('status', 'Tenant saved.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        return view('livewire.tenants.index', [
            'tenants' => Tenant::query()
                ->withCount('leases')
                ->when($this->q !== '', function ($query): void {
                    $term = '%'.$this->q.'%';
                    $query->where(fn ($inner) => $inner
                        ->where('name', 'like', $term)
                        ->orWhere('national_id', 'like', $term)
                        ->orWhere('company_reg_no', 'like', $term)
                        ->orWhere('mobile', 'like', $term)
                        ->orWhere('email', 'like', $term));
                })
                ->when($this->typeFilter !== '', fn ($query) => $query->where('type', $this->typeFilter))
                ->orderBy('name')
                ->orderBy('id') // deterministic tiebreak for equal names
                ->paginate(10),
            'types' => TenantType::cases(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(
            'editingId', 'type', 'name', 'national_id', 'company_reg_no',
            'contact_person', 'mobile', 'email', 'sms_opt_out', 'postal_address',
        );
        $this->resetValidation();
    }
}
