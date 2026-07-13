{{-- Centred modal (council DS Dialog): navy blurred scrim, 18px radius, Sora
     title + optional description, deep soft shadow. Backdrop click closes; pair
     with wire:keydown.escape.window on the page root for Esc. Slot supplies the
     body/footer (usually a <form>; footers use bg-sunken + rounded-b-xl). --}}
@props(['title', 'description' => null, 'close' => 'cancel', 'wide' => false])

<div class="overlay-enter fixed inset-0 z-[60] overflow-y-auto bg-navy/40 backdrop-blur-[2px]">
    <div wire:click.self="{{ $close }}" class="flex min-h-full items-center justify-center p-4 sm:p-6">
        <div class="w-full {{ $wide ? 'max-w-[760px]' : 'max-w-[480px]' }} rounded-xl bg-surface shadow-modal"
            role="dialog" aria-modal="true" aria-label="{{ $title }}">
            <div class="flex items-start justify-between gap-3 px-5 pb-2 pt-5">
                <div class="min-w-0">
                    <h3 class="text-20 font-bold text-ink">{{ $title }}</h3>
                    @if ($description)
                        <p class="mt-1 text-13 text-muted">{{ $description }}</p>
                    @endif
                </div>
                <button type="button" wire:click="{{ $close }}" class="icon-btn -mr-1 -mt-1" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            {{ $slot }}
        </div>
    </div>
</div>
