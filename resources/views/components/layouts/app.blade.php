<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-sunken font-sans text-14 text-ink antialiased">
    @php
        $user = auth()->user();
        $initials = collect(explode(' ', (string) $user?->name))
            ->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
        $canCreate = $user?->canAny([
            \App\Enums\Permission::ManageLeases->value,
            \App\Enums\Permission::ManageTenants->value,
            \App\Enums\Permission::ManageProperties->value,
        ]);
    @endphp

    {{-- ============ Top bar (56px, design PRD §4.1) ============ --}}
    <header class="fixed inset-x-0 top-0 z-40 flex h-14 items-center gap-2 border-b border-line bg-surface px-3">
        <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2 pl-1 pr-3">
            <span class="grid h-7 w-7 place-items-center rounded-md bg-brand-500 text-14 font-bold text-white">K</span>
            <span class="hidden font-semibold text-ink sm:block">Kuli</span>
            <span class="hidden text-12 text-muted lg:block">· Lease Management</span>
        </a>

        {{-- Global search → leases list --}}
        @can(\App\Enums\Permission::ManageLeases->value)
            <form action="{{ route('leases.index') }}" method="GET" class="mx-auto hidden max-w-xl flex-1 sm:block">
                <label class="relative block">
                    <span class="absolute inset-y-0 left-2.5 grid place-items-center text-muted">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    </span>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Search leases, tenants, properties…"
                        class="h-9 w-full rounded border border-line bg-sunken pl-9 pr-3 text-13 placeholder:text-muted hover:bg-hover focus:border-brand-500 focus:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300">
                </label>
            </form>
        @endcan

        <div class="ml-auto flex items-center gap-2">
            {{-- Create menu (design PRD §6.6) --}}
            @if ($canCreate)
                <details class="relative">
                    <summary class="btn-primary cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                        <span class="hidden sm:inline">Create</span>
                    </summary>
                    <div class="absolute right-0 z-50 mt-1.5 w-72 rounded-lg border border-line bg-surface p-2 shadow-overlay">
                        @can(\App\Enums\Permission::ManageLeases->value)
                            <a href="{{ route('leases.index', ['create' => 1]) }}" class="menu-row">
                                <span class="grid h-8 w-8 place-items-center rounded bg-brand-50 text-brand-600">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                </span>
                                <span class="text-left"><span class="block text-13 font-medium text-ink">New lease</span><span class="block text-12 text-muted">Link a property to a tenant</span></span>
                            </a>
                        @endcan
                        @can(\App\Enums\Permission::ManageTenants->value)
                            <a href="{{ route('tenants.index', ['create' => 1]) }}" class="menu-row">
                                <span class="grid h-8 w-8 place-items-center rounded bg-discovery-bg text-discovery-fg">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/></svg>
                                </span>
                                <span class="text-left"><span class="block text-13 font-medium text-ink">New tenant</span><span class="block text-12 text-muted">Individual or organisation</span></span>
                            </a>
                        @endcan
                        @can(\App\Enums\Permission::ManageProperties->value)
                            <a href="{{ route('properties.index', ['create' => 1]) }}" class="menu-row">
                                <span class="grid h-8 w-8 place-items-center rounded bg-success-bg text-success-fg">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M6 21V7l6-4 6 4v14"/></svg>
                                </span>
                                <span class="text-left"><span class="block text-13 font-medium text-ink">New property</span><span class="block text-12 text-muted">Register a land parcel</span></span>
                            </a>
                        @endcan
                    </div>
                </details>
            @endif

            <a href="{{ route('settings.profile') }}" title="My account"
                class="flex items-center gap-2 rounded px-1.5 py-1 hover:bg-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300 {{ request()->routeIs('settings.profile') ? 'bg-selected' : '' }}">
                <span class="hidden text-13 text-subtle md:block">{{ $user?->name }}</span>
                <span class="grid h-7 w-7 place-items-center rounded-full bg-discovery-fg text-12 font-semibold text-white">{{ $initials }}</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn-subtle">Sign out</button>
            </form>
        </div>
    </header>

    <div class="flex pt-14">
        {{-- ============ Sidebar (240px, design PRD §4.1) ============ --}}
        <aside class="fixed bottom-0 left-0 top-14 z-30 hidden w-60 flex-col border-r border-line bg-surface md:flex">
            <nav class="flex-1 space-y-0.5 overflow-y-auto px-2 py-3">
                <p class="px-3 pb-1.5 pt-1 text-11 font-semibold uppercase tracking-wide text-muted">Council · Land &amp; Property</p>

                <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'nav-item-active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    Dashboard
                </a>

                @can(\App\Enums\Permission::ManageLeases->value)
                    <a href="{{ route('leases.index') }}" class="nav-item {{ request()->routeIs('leases.*') ? 'nav-item-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></svg>
                        Leases
                        <span class="ml-auto rounded-full bg-line-2 px-1.5 py-0.5 text-11 font-semibold text-muted">{{ \App\Models\Lease::count() }}</span>
                    </a>
                @endcan
                @can(\App\Enums\Permission::ManageTenants->value)
                    <a href="{{ route('tenants.index') }}" class="nav-item {{ request()->routeIs('tenants.*') ? 'nav-item-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>
                        Tenants
                    </a>
                @endcan
                @can(\App\Enums\Permission::ManageProperties->value)
                    <a href="{{ route('properties.index') }}" class="nav-item {{ request()->routeIs('properties.*') ? 'nav-item-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M6 21V7l6-4 6 4v14"/><path d="M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h.01M15 17h.01"/></svg>
                        Properties
                    </a>
                @endcan

                @can(\App\Enums\Permission::IssueInvoices->value)
                    <div class="my-2 border-t border-line-2"></div>
                    <a href="{{ route('invoices.index') }}" class="nav-item {{ request()->routeIs('invoices.*') ? 'nav-item-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>
                        Invoices
                        @php $overdueCount = \App\Models\Invoice::where('status', \App\Enums\InvoiceStatus::Overdue->value)->count(); @endphp
                        @if ($overdueCount > 0)
                            <span class="ml-auto rounded-full bg-danger-bg px-1.5 py-0.5 text-11 font-semibold text-danger-fg">{{ $overdueCount }}</span>
                        @endif
                    </a>
                @endcan
                @can('view reports')
                    <a href="{{ route('reports.arrears') }}" class="nav-item {{ request()->routeIs('reports.*') ? 'nav-item-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M7 15l3-4 3 2 4-6"/></svg>
                        Reports
                    </a>
                @endcan

                @canany([\App\Enums\Permission::ManageUsers->value, \App\Enums\Permission::ConfigureNotifications->value])
                    <div class="my-2 border-t border-line-2"></div>
                    <p class="px-3 pb-1 pt-2 text-11 font-semibold uppercase tracking-wide text-muted">Settings</p>
                @endcanany
                @can(\App\Enums\Permission::ManageUsers->value)
                    <a href="{{ route('settings.users') }}" class="nav-item {{ request()->routeIs('settings.users') ? 'nav-item-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M19 8v6M22 11h-6"/></svg>
                        Users &amp; roles
                    </a>
                @endcan
                @can(\App\Enums\Permission::ConfigureNotifications->value)
                    <a href="{{ route('settings.reminders') }}" class="nav-item {{ request()->routeIs('settings.reminders') ? 'nav-item-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                        Reminders &amp; SMS
                    </a>
                @endcan
            </nav>

            <div class="border-t border-line-2 p-3">
                <div class="flex items-center gap-2 rounded p-2">
                    <span class="grid h-7 w-7 place-items-center rounded bg-brand-50 text-12 font-bold text-brand-600">MC</span>
                    <div class="min-w-0">
                        <p class="truncate text-12 font-medium text-ink">Malé City Council</p>
                        <p class="truncate text-11 text-muted">Revenue Section</p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="w-full md:pl-60">
            <div class="mx-auto w-full max-w-[1200px] px-4 py-6 sm:px-6">
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
