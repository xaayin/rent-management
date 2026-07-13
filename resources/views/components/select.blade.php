{{-- Custom dropdown (council DS Select): white field, chevron, focus ring, and a
     fully styled options panel. Entangles with wire:model / wire:model.live via
     Alpine (bundled with Livewire). `chip` renders the toolbar-filter variant —
     pass the filter's name as the '' option label (e.g. ['' => 'Status', …]). --}}
@props([
    'options' => [],
    'placeholder' => 'Select…',
    'chip' => false,
    'value' => null, // static initial value for non-wire:model usage (fires a 'changed' event)
])

@php
    $normalised = collect($options)
        ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])
        ->values();
    $hasWireModel = filled($attributes->wire('model')->value());
@endphp

<div
    x-data="{
        open: false,
        value: @if ($hasWireModel) @entangle($attributes->wire('model')) @else {{ \Illuminate\Support\Js::from($value) }} @endif,
        options: {{ \Illuminate\Support\Js::from($normalised) }},
        current() {
            const v = this.value === null || this.value === undefined ? '' : String(this.value);
            return this.options.find(o => o.value === v) ?? null;
        },
        isSelected(v) {
            const c = this.value === null || this.value === undefined ? '' : String(this.value);
            return c === v;
        },
        choose(v) { this.value = v; this.open = false; this.$dispatch('changed', v); },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.stop="open = false"
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'relative']) }}
>
    @if ($chip)
        <button type="button" x-on:click="open = ! open" aria-haspopup="listbox" x-bind:aria-expanded="open"
            class="chip" x-bind:class="(current() && current().value !== '') ? 'border-brand-500 text-brand-600' : ''">
            <span x-text="current() ? current().label : @js($placeholder)"></span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="text-faint"><path d="m6 9 6 6 6-6"/></svg>
        </button>
    @else
        <button type="button" x-on:click="open = ! open" aria-haspopup="listbox" x-bind:aria-expanded="open"
            class="flex h-9 w-full items-center justify-between gap-2 rounded border border-line bg-surface px-3 text-left text-13 shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300"
            x-bind:class="open ? 'border-brand-500 ring-2 ring-brand-300/40' : ''">
            <span class="truncate" x-text="current() ? current().label : @js($placeholder)"
                x-bind:class="(current() && current().value !== '') ? 'text-ink' : 'text-muted'"></span>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="shrink-0 text-faint"><path d="m6 9 6 6 6-6"/></svg>
        </button>
    @endif

    <div x-cloak x-show="open" x-transition.opacity.duration.120ms role="listbox"
        class="absolute left-0 top-full z-[80] mt-1.5 max-h-64 w-full min-w-44 overflow-y-auto rounded-md border border-line bg-surface p-1.5 shadow-overlay">
        <template x-for="opt in options" x-bind:key="opt.value">
            <button type="button" role="option" x-on:click="choose(opt.value)" x-bind:aria-selected="isSelected(opt.value)"
                class="flex w-full items-center justify-between gap-2 rounded px-2.5 py-2 text-left text-13"
                x-bind:class="isSelected(opt.value) ? 'bg-selected font-semibold text-brand-600' : 'text-ink hover:bg-hover'">
                <span class="truncate" x-text="opt.label"></span>
                <svg x-show="isSelected(opt.value)" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="shrink-0"><path d="M20 6 9 17l-5-5"/></svg>
            </button>
        </template>
    </div>
</div>
