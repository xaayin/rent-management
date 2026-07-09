<x-layouts.guest :title="__('Sign in')">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex items-center justify-center gap-2">
            <span class="flex h-8 w-8 items-center justify-center rounded bg-brand-500 text-sm font-semibold text-white">K</span>
            <span class="text-base font-semibold text-ink">Kuli · Lease Management</span>
        </div>

        <div class="rounded-md border border-line bg-surface p-6 shadow-card">
            <h1 class="mb-1 text-xl font-semibold text-ink">Sign in</h1>
            <p class="mb-5 text-[13px] text-muted">Council staff access only.</p>

            @if ($errors->any())
                <div class="mb-4 rounded border border-danger-fg/20 bg-danger-bg px-3 py-2 text-[13px] text-danger-fg">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="email" class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                        class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                </div>

                <div>
                    <label for="password" class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                        class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                </div>

                <label class="flex items-center gap-2 text-[13px] text-subtle">
                    <input type="checkbox" name="remember" class="rounded border-line text-brand-500 focus:ring-brand-300">
                    Remember me
                </label>

                <button type="submit"
                    class="h-8 w-full rounded bg-brand-500 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">
                    Sign in
                </button>
            </form>
        </div>
    </div>
</x-layouts.guest>
