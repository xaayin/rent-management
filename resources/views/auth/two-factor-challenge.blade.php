<x-layouts.guest :title="__('Two-factor authentication')">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex items-center justify-center gap-2">
            <img src="{{ asset('images/council/mark-color.png') }}" alt="" class="h-9 w-auto">
            <span class="font-display text-base font-bold text-navy">Kanduhulhudhoo Council</span>
        </div>

        <div class="rounded-md border border-line bg-surface p-6 shadow-card">
            <h1 class="mb-1 text-xl font-semibold text-ink">Two-factor authentication</h1>
            <p class="mb-5 text-[13px] text-muted">Enter the 6-digit code from your authenticator app.</p>

            @if ($errors->any())
                <div class="mb-4 rounded border border-danger-fg/20 bg-danger-bg px-3 py-2 text-[13px] text-danger-fg">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ url('/two-factor-challenge') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="code" class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">Authentication code</label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus
                        class="h-9 w-full rounded border border-line bg-surface px-3 text-center text-lg tracking-[0.4em] text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                </div>

                <button type="submit"
                    class="h-8 w-full rounded bg-brand-500 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">
                    Verify
                </button>
            </form>

            <details class="mt-4">
                <summary class="cursor-pointer text-[13px] text-brand-600 hover:underline">Lost your device? Use a recovery code</summary>
                <form method="POST" action="{{ url('/two-factor-challenge') }}" class="mt-3 space-y-3">
                    @csrf
                    <div>
                        <label for="recovery_code" class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">Recovery code</label>
                        <input id="recovery_code" name="recovery_code" type="text" autocomplete="off"
                            class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    </div>
                    <button type="submit"
                        class="h-8 w-full rounded border border-line bg-surface text-sm font-medium text-subtle transition hover:bg-hover">
                        Use recovery code
                    </button>
                </form>
            </details>
        </div>
    </div>
</x-layouts.guest>
