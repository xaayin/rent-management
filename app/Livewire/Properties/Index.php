<?php

declare(strict_types=1);

namespace App\Livewire\Properties;

use App\Enums\UsageType;
use App\Models\Property;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
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
    }

    public function create(): void
    {
        $this->authorize('create', Property::class);
        $this->resetForm();
        $this->showForm = true;
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
            'properties' => Property::withCount('activeLeases')->orderBy('name')->get(),
            'usageTypes' => UsageType::cases(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'land_number', 'size_sqft', 'usage_type', 'location_notes');
        $this->resetValidation();
    }
}
