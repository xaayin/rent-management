<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-sunken font-sans text-ink antialiased">
    {{-- Top bar (56px) --}}
    <header class="fixed inset-x-0 top-0 z-20 flex h-14 items-center justify-between border-b border-line bg-surface px-4">
        <div class="flex items-center gap-2">
            <span class="flex h-8 w-8 items-center justify-center rounded bg-brand-500 text-sm font-semibold text-white">K</span>
            <span class="text-sm font-semibold text-ink">Kuli · Lease Management</span>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-[13px] text-subtle">{{ auth()->user()?->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="h-8 rounded px-3 text-[13px] font-medium text-subtle transition hover:bg-hover">
                    Sign out
                </button>
            </form>
        </div>
    </header>

    <div class="flex pt-14">
        {{-- Sidebar (240px) --}}
        <aside class="fixed bottom-0 left-0 top-14 hidden w-60 border-r border-line bg-surface px-3 py-4 md:block">
            @php
                $navClass = fn (bool $active) => $active
                    ? 'flex h-9 items-center rounded px-3 text-sm font-medium text-brand-600 bg-selected'
                    : 'flex h-9 items-center rounded px-3 text-sm font-medium text-subtle hover:bg-hover';
            @endphp
            <nav class="space-y-0.5">
                <a href="{{ route('dashboard') }}" class="{{ $navClass(request()->routeIs('dashboard')) }}">Dashboard</a>

                @can(\App\Enums\Permission::ManageLeases->value)
                    <a href="{{ route('leases.index') }}" class="{{ $navClass(request()->routeIs('leases.*')) }}">Leases</a>
                @endcan
                @can(\App\Enums\Permission::IssueInvoices->value)
                    <a href="{{ route('invoices.index') }}" class="{{ $navClass(request()->routeIs('invoices.*')) }}">Invoices</a>
                @endcan
                @can(\App\Enums\Permission::ManageTenants->value)
                    <a href="{{ route('tenants.index') }}" class="{{ $navClass(request()->routeIs('tenants.*')) }}">Tenants</a>
                @endcan
                @can(\App\Enums\Permission::ManageProperties->value)
                    <a href="{{ route('properties.index') }}" class="{{ $navClass(request()->routeIs('properties.*')) }}">Properties</a>
                @endcan

                @canany([\App\Enums\Permission::ManageUsers->value, \App\Enums\Permission::ConfigureNotifications->value])
                    <p class="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wide text-muted">Settings</p>
                @endcanany
                @can(\App\Enums\Permission::ManageUsers->value)
                    <a href="{{ route('settings.users') }}" class="{{ $navClass(request()->routeIs('settings.users')) }}">Users &amp; roles</a>
                @endcan
                @can(\App\Enums\Permission::ConfigureNotifications->value)
                    <a href="{{ route('settings.reminders') }}" class="{{ $navClass(request()->routeIs('settings.reminders')) }}">Reminders &amp; SMS</a>
                @endcan
            </nav>
        </aside>

        <main class="w-full md:pl-60">
            <div class="mx-auto w-full max-w-[1200px] px-6 py-8">
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
