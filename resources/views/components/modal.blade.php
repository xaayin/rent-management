{{-- Centred modal (design PRD §5.8): dimmed backdrop (click to close), lg
     radius, overlay shadow. Pair with wire:keydown.escape.window on the page
     root for Esc. Slot supplies the body/footer (usually a <form>). --}}
@props(['title', 'close' => 'cancel', 'wide' => false])

<div wire:click.self="{{ $close }}"
    class="overlay-enter fixed inset-0 z-[60] grid place-items-start justify-center overflow-y-auto bg-ink/40 p-4 sm:p-8">
    <div class="mt-10 w-full {{ $wide ? 'max-w-[760px]' : 'max-w-[480px]' }} rounded-lg bg-surface shadow-overlay"
        role="dialog" aria-modal="true" aria-label="{{ $title }}">
        <div class="flex items-center justify-between border-b border-line-2 px-5 py-4">
            <h3 class="text-16 font-semibold text-ink">{{ $title }}</h3>
            <button type="button" wire:click="{{ $close }}" class="icon-btn" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
