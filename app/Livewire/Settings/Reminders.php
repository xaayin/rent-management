<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\Permission;
use App\Models\ReminderRule;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Reminder schedule & template configuration (FR-NOT-02/03) — restricted to
 * "Configure notifications / SMS provider" holders: Administrators only
 * (PRD §6.1).
 */
#[Layout('components.layouts.app')]
class Reminders extends Component
{
    /** @var array<int, array{kind: string, label: string, enabled: bool, days: int, template: string}> */
    public array $rules = [];

    public function mount(): void
    {
        $this->authorizeAccess();

        foreach (ReminderRule::query()->orderBy('id')->get() as $rule) {
            $this->rules[$rule->id] = [
                'kind' => $rule->kind->value,
                'label' => $rule->kind->label(),
                'enabled' => $rule->enabled,
                'days' => $rule->days,
                'template' => $rule->template,
            ];
        }
    }

    public function save(): void
    {
        $this->authorizeAccess();

        $this->validate([
            'rules.*.enabled' => ['boolean'],
            'rules.*.days' => ['required', 'integer', 'min:0', 'max:60'],
            // ~3 concatenated SMS segments (INT-SMS-02).
            'rules.*.template' => ['required', 'string', 'max:480'],
        ], [
            'rules.*.days.max' => 'Reminders can be at most 60 days from the due date.',
            'rules.*.template.required' => 'The message template cannot be empty.',
        ]);

        foreach ($this->rules as $id => $attributes) {
            ReminderRule::findOrFail($id)->update([
                'enabled' => (bool) $attributes['enabled'],
                'days' => (int) $attributes['days'],
                'template' => $attributes['template'],
            ]);
        }

        session()->flash('status', 'Reminder settings saved.');
    }

    public function render(): View
    {
        return view('livewire.settings.reminders', [
            'smsDriver' => (string) config('sms.driver'),
        ]);
    }

    private function authorizeAccess(): void
    {
        abort_unless(
            (bool) auth()->user()?->hasPermissionTo(Permission::ConfigureNotifications->value),
            403,
        );
    }
}
