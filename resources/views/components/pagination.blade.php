{{-- Issue-list pagination footer (design PRD §5.4): "Showing 1–10 of 18" plus
     windowed page buttons. Expects a Livewire-driven LengthAwarePaginator. --}}
@props(['paginator'])

@if ($paginator->total() > 0)
    <div class="flex h-12 items-center justify-between border-t border-line-2 px-4 text-12 text-muted">
        <span>Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
        @if ($paginator->hasPages())
            <div class="flex items-center gap-1">
                <button wire:click="previousPage" @disabled($paginator->onFirstPage()) class="pg" aria-label="Previous page">‹</button>
                @php
                    $window = range(
                        max(1, $paginator->currentPage() - 2),
                        min($paginator->lastPage(), $paginator->currentPage() + 2),
                    );
                @endphp
                @if ($window[0] > 1)
                    <button wire:click="gotoPage(1)" class="pg">1</button>
                    @if ($window[0] > 2)<span class="px-0.5">…</span>@endif
                @endif
                @foreach ($window as $page)
                    <button wire:click="gotoPage({{ $page }})" class="pg {{ $page === $paginator->currentPage() ? 'pg-active' : '' }}">{{ $page }}</button>
                @endforeach
                @if (end($window) < $paginator->lastPage())
                    @if (end($window) < $paginator->lastPage() - 1)<span class="px-0.5">…</span>@endif
                    <button wire:click="gotoPage({{ $paginator->lastPage() }})" class="pg">{{ $paginator->lastPage() }}</button>
                @endif
                <button wire:click="nextPage" @disabled(! $paginator->hasMorePages()) class="pg" aria-label="Next page">›</button>
            </div>
        @endif
    </div>
@endif
