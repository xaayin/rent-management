<x-layouts.guest :title="__('Sign in')">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <img src="{{ asset('images/council/lockup-stacked-color.png') }}" alt="Secretariat of the Kanduhulhudhoo Council" class="mx-auto h-24 w-auto">
            <p class="mt-3 text-11 font-bold uppercase tracking-[0.08em] text-muted">Land &amp; Property Lease Management</p>
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
                    <label for="email" class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                        class="input ">
                </div>

                <div>
                    <label for="password" class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                        class="input ">
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
