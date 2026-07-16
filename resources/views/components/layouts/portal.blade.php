<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Tenant portal · Kanduhulhudhoo Council' }}</title>
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Mobile-first: most tenants will open this on a phone. --}}
<body class="min-h-screen bg-canvas font-sans text-14 text-ink antialiased">
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex h-16 w-full max-w-3xl items-center gap-2.5 px-4">
            <img src="{{ asset('images/council/mark-color.png') }}" alt="" class="h-8 w-auto">
            <span class="leading-tight">
                <span class="block font-display text-13 font-bold tracking-[-0.01em] text-navy">Kanduhulhudhoo Council</span>
                <span class="block text-11 font-semibold text-muted">Tenant portal</span>
            </span>
        </div>
    </header>

    <main class="mx-auto w-full max-w-3xl px-4 py-5">
        {{ $slot }}
    </main>

    <footer class="mx-auto w-full max-w-3xl px-4 pb-8 pt-2 text-11 text-faint">
        Secretariat of the Kanduhulhudhoo Council · Ga. Kanduhulhudhoo · Tel 6820020
    </footer>
</body>
</html>
