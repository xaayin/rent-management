<div class="mx-auto mt-6 w-full max-w-sm">
    @if ($bypassHint)
        <div class="mb-3 rounded-md border border-warning-bg bg-warning-bg/50 px-4 py-2.5 text-12 text-warning-fg">
            <span class="font-bold uppercase tracking-[0.08em]">Testing mode</span> —
            SMS is off; sign in any registered number with code <span class="font-mono font-bold">{{ $bypassHint }}</span>.
        </div>
    @endif

    <div class="rounded-md border border-line bg-surface p-6 shadow-card">
        @if ($step === 'mobile')
            <h1 class="mb-1 text-20 font-bold text-ink">Sign in</h1>
            <p class="mb-5 text-13 text-muted">Enter the mobile number the council has on record for you. We'll text you a sign-in code — no password needed.</p>

            <form wire:submit="requestCode" class="space-y-4">
                <div>
                    <label for="mobile" class="fl mb-1 block">Mobile number</label>
                    <input id="mobile" type="tel" wire:model="mobile" inputmode="tel" autocomplete="tel" placeholder="7XXXXXX" class="input" autofocus>
                    @error('mobile') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-primary w-full justify-center">
                    <span wire:loading.remove wire:target="requestCode">Text me a code</span>
                    <span wire:loading wire:target="requestCode">Sending…</span>
                </button>
            </form>
        @elseif ($step === 'code')
            <h1 class="mb-1 text-20 font-bold text-ink">Enter the code</h1>
            {{-- Deliberately the same message whether or not the number is
                 registered — this page must not reveal who our tenants are. --}}
            <p class="mb-5 text-13 text-muted">If <span class="font-semibold text-ink">{{ $mobile }}</span> is registered with the council, it has just received a 6-digit code. It expires in 5 minutes.</p>

            <form wire:submit="verify" class="space-y-4">
                <div>
                    <label for="code" class="fl mb-1 block">Sign-in code</label>
                    <input id="code" type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                        class="input text-center text-lg tracking-[0.4em]" autofocus>
                    @error('code') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-primary w-full justify-center">
                    <span wire:loading.remove wire:target="verify">Sign in</span>
                    <span wire:loading wire:target="verify">Checking…</span>
                </button>
                <button type="button" wire:click="$set('step', 'mobile')" class="btn-subtle w-full justify-center">Use a different number</button>
            </form>
        @else
            <h1 class="mb-1 text-20 font-bold text-ink">Choose your account</h1>
            <p class="mb-5 text-13 text-muted">This mobile number is registered against more than one tenancy. Pick the one you want to open.</p>

            <div class="space-y-2">
                @foreach ($choices as $choice)
                    <button wire:click="choose({{ $choice->id }})"
                        class="flex w-full items-center justify-between gap-3 rounded border border-line bg-surface px-4 py-3 text-left shadow-xs hover:bg-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300">
                        <span class="min-w-0">
                            <span class="block truncate text-13 font-semibold text-ink">{{ $choice->name }}</span>
                            <span class="block text-12 text-muted">{{ $choice->type->label() }}{{ $choice->registryNumber() ? ' · '.$choice->registryNumber() : '' }}</span>
                        </span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0 text-faint"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</div>
