<?php

declare(strict_types=1);

namespace App\Livewire\Properties;

use App\Enums\PropertyStatus;
use App\Enums\UsageType;
use App\Models\Property;
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

    /** Free-text filter: name or land number. */
    #[Url]
    public string $q = '';

    /** Filter chips (design PRD §5.5). */
    #[Url]
    public string $usageFilter = '';

    #[Url]
    public string $statusFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $land_number = '';

    public ?int $size_sqft = null;

    public string $usage_type = '';

    public string $location_notes = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Property::class);

        // The top-bar Create menu deep-links here (design PRD §6.6).
        if (request()->boolean('create')) {
            $this->create();
        }
    }

    public function create(): void
    {
        $this->authorize('create', Property::class);
        $this->resetForm();
        $this->showForm = true;
    }

    /**
     * Any filter change jumps back to page 1 so results never vanish behind a
     * stale page number.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'usageFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('q', 'usageFilter', 'statusFilter');
        $this->resetPage();
    }

    public function edit(int $id): void
    {
        $property = Property::findOrFail($id);
        $this->authorize('update', $property);

        $this->editingId = $property->id;
        $this->name = $property->name;
        $this->land_number = $property->land_number;
        $this->size_sqft = $property->size_sqft;
        $this->usage_type = $property->usage_type->value;
        $this->location_notes = (string) $property->location_notes;
        $this->showForm = true;
    }

    public function save(): void
    {
        $property = $this->editingId ? Property::findOrFail($this->editingId) : null;

        $this->authorize($property ? 'update' : 'create', $property ?? Property::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'land_number' => ['required', 'string', 'max:255', Rule::unique('properties', 'land_number')->ignore($this->editingId)],
            'size_sqft' => ['required', 'integer', 'min:1'],
            'usage_type' => ['required', Rule::enum(UsageType::class)],
            'location_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($property) {
            $property->update($validated);
        } else {
            Property::create($validated);
        }

        $this->resetForm();
        $this->showForm = false;
        session()->flash('status', 'Property saved.');
    }

    public function archive(int $id): void
    {
        $property = Property::findOrFail($id);
        $this->authorize('update', $property);
        $property->archive();
    }

    public function restore(int $id): void
    {
        $property = Property::findOrFail($id);
        $this->authorize('update', $property);
        $property->restore();
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function render(): View
    {
        return view('livewire.properties.index', [
            'properties' => Property::query()
                ->withCount('activeLeases')
                ->when($this->q !== '', function ($query): void {
                    $term = '%'.$this->q.'%';
                    $query->where(fn ($inner) => $inner
                        ->where('name', 'like', $term)
                        ->orWhere('land_number', 'like', $term));
                })
                ->when($this->usageFilter !== '', fn ($query) => $query->where('usage_type', $this->usageFilter))
                ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
                ->orderBy('name')
                ->orderBy('id') // deterministic tiebreak for equal names
                ->paginate(10),
            'usageTypes' => UsageType::cases(),
            'propertyStatuses' => PropertyStatus::cases(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'land_number', 'size_sqft', 'usage_type', 'location_notes');
        $this->resetValidation();
    }
}
