<div>
    <nav class="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-muted">Settings / Reminders</nav>
    <h1 class="mb-1 text-2xl font-semibold text-ink">Reminders &amp; SMS</h1>
    <p class="mb-6 text-[13px] text-muted">Configure when payment reminders are sent and what they say (PRD §4.8).</p>
    <x-toast />

    <div class="mb-6 rounded-md border border-line bg-surface p-4 shadow-card">
        <p class="text-[13px] text-subtle">
            <span class="font-semibold text-ink">SMS provider:</span>
            <span class="loz loz-info">{{ $smsDriver }} driver</span>
            — messages are written to the application log until the council's SMS gateway is configured
            (<code class="rounded bg-sunken px-1 py-0.5 text-[12px]">config/sms.php</code>).
        </p>
        <p class="mt-2 text-[13px] text-muted">
            Merge fields: <code class="rounded bg-sunken px-1 py-0.5 text-[12px]">{{ implode(' ', \App\Services\Reminders\TemplateRenderer::FIELDS) }}</code>
        </p>
    </div>

    <form wire:submit="save" class="space-y-4">
        @foreach ($rules as $id => $rule)
            <div class="rounded-md border border-line bg-surface p-5 shadow-card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-ink">{{ $rule['label'] }}</h2>
                    <label class="flex items-center gap-2 text-[13px] text-subtle">
                        <input type="checkbox" wire:model="rules.{{ $id }}.enabled" class="rounded border-line text-brand-500 focus:ring-brand-300">
                        Enabled
                    </label>
                </div>

                @php $kindEnum = \App\Enums\ReminderKind::from($rule['kind']); @endphp

                {{-- Days only makes sense for the due-date-driven kinds; the
                     confirmation fires on receipt, the statement monthly. --}}
                @if ($kindEnum->isDueDateDriven() && $rule['kind'] !== 'on_due')
                    <div class="mb-3 max-w-[200px]">
                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">
                            Days {{ $rule['kind'] === 'pre_due' ? 'before' : 'after' }} the due date
                        </label>
                        <input type="number" wire:model="rules.{{ $id }}.days"
                            class="input ">
                        @error('rules.'.$id.'.days') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                @elseif ($rule['kind'] === 'payment_confirmation')
                    <p class="mb-3 text-[13px] text-muted">Sent automatically the moment a receipt is issued.</p>
                @elseif ($rule['kind'] === 'balance_statement')
                    <p class="mb-3 text-[13px] text-muted">Sent on the 1st of each month to tenants with an outstanding balance.</p>
                @endif

                {{-- Each kind renders from its own merge-field set. --}}
                @php
                    $fields = match ($kindEnum) {
                        \App\Enums\ReminderKind::PaymentConfirmation => \App\Services\Reminders\TemplateRenderer::RECEIPT_FIELDS,
                        \App\Enums\ReminderKind::BalanceStatement => \App\Services\Reminders\TemplateRenderer::TENANT_FIELDS,
                        default => \App\Services\Reminders\TemplateRenderer::FIELDS,
                    };
                @endphp
                <p class="mb-2 text-[12px] text-muted">Fields: <code class="rounded bg-sunken px-1 py-0.5 text-[11px]">{{ implode(' ', $fields) }}</code></p>

                <label class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">Message template</label>
                <textarea wire:model="rules.{{ $id }}.template" rows="3"
                    class="w-full rounded border border-line bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300"></textarea>
                @error('rules.'.$id.'.template') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <button type="submit"
            class="h-8 rounded bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">
            Save reminder settings
        </button>
    </form>
</div>
