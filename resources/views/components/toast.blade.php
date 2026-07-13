{{-- Transient confirmation flag (design PRD §5.9): dark pill, bottom-centre,
     auto-dismisses via CSS so it works on every Livewire update. --}}
@if (session('status'))
    <div class="toast-auto pointer-events-none fixed bottom-5 left-1/2 z-[70] -translate-x-1/2" role="status">
        <div class="flex items-center gap-3 rounded-md bg-navy py-2.5 pl-3 pr-4 text-13 text-white shadow-overlay">
            <span class="grid h-5 w-5 place-items-center rounded-full bg-success-fg">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
            </span>
            <span>{{ session('status') }}</span>
        </div>
    </div>
@endif
