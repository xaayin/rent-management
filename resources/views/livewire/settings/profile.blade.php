<div>
    <x-toast />

    <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Settings</span><span>/</span><span class="text-subtle">My account</span></nav>
    <h1 class="mb-1 text-24 font-semibold text-ink">My account</h1>
    <p class="mb-6 text-13 text-muted">Your profile, password, two-factor authentication and sessions.</p>

    <div class="max-w-2xl space-y-6">
        {{-- ============ Profile ============ --}}
        <div class="rounded-md border border-line bg-surface shadow-card">
            <div class="flex h-12 items-center border-b border-line-2 px-5">
                <h2 class="text-14 font-semibold text-ink">Profile</h2>
            </div>
            <form wire:submit="updateProfile" class="space-y-4 p-5">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="fl">Name</label>
                        <input type="text" wire:model="name" class="input mt-1">
                        @error('name') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">Email</label>
                        <input type="email" wire:model="email" class="input mt-1">
                        @error('email') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                </div>
                <button type="submit" class="btn-primary">Save profile</button>
            </form>
        </div>

        {{-- ============ Password ============ --}}
        <div class="rounded-md border border-line bg-surface shadow-card">
            <div class="flex h-12 items-center border-b border-line-2 px-5">
                <h2 class="text-14 font-semibold text-ink">Change password</h2>
            </div>
            <form wire:submit="updatePassword" class="space-y-4 p-5">
                <div>
                    <label class="fl">Current password</label>
                    <input type="password" wire:model="current_password" autocomplete="current-password" class="input mt-1">
                    @error('current_password') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="fl">New password</label>
                        <input type="password" wire:model="password" autocomplete="new-password" class="input mt-1">
                        @error('password') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">Confirm new password</label>
                        <input type="password" wire:model="password_confirmation" autocomplete="new-password" class="input mt-1">
                    </div>
                </div>
                <button type="submit" class="btn-primary">Change password</button>
            </form>
        </div>

        {{-- ============ Two-factor authentication (FR-SEC-03) ============ --}}
        <div class="rounded-md border border-line bg-surface shadow-card">
            <div class="flex h-12 items-center justify-between border-b border-line-2 px-5">
                <h2 class="text-14 font-semibold text-ink">Two-factor authentication</h2>
                @if ($twoFactorState === 'enabled')
                    <span class="loz loz-success">Enabled</span>
                @elseif ($twoFactorState === 'pending')
                    <span class="loz loz-warning">Confirm to finish</span>
                @else
                    <span class="loz loz-neutral">Off</span>
                @endif
            </div>
            <div class="space-y-4 p-5">
                @if ($twoFactorState === 'disabled')
                    <p class="text-13 text-subtle">
                        Add a second sign-in step using an authenticator app (Google Authenticator, 1Password, Authy…).
                        @if ($privilegedRole)
                            <span class="loz loz-warning ml-1">Recommended for your role</span>
                        @endif
                    </p>
                    <div class="max-w-xs">
                        <label class="fl">Confirm your password to begin</label>
                        <input type="password" wire:model="twofa_password" autocomplete="current-password" class="input mt-1">
                        @error('twofa_password') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <button wire:click="enableTwoFactor" class="btn-primary">Enable two-factor</button>

                @elseif ($twoFactorState === 'pending')
                    <p class="text-13 text-subtle">Scan the QR code with your authenticator app, then enter the 6-digit code it shows to finish.</p>
                    <div class="flex items-start gap-5">
                        <div class="shrink-0 rounded border border-line bg-white p-2">{!! $qrCodeSvg !!}</div>
                        <div class="min-w-0 space-y-3">
                            <div>
                                <p class="fl">Or enter this key manually</p>
                                <code class="mt-1 block break-all rounded bg-sunken px-2 py-1 text-13">{{ $secretKey }}</code>
                            </div>
                            <div class="max-w-[220px]">
                                <label class="fl">Code from your app</label>
                                <input type="text" wire:model="twofa_code" inputmode="numeric" autocomplete="one-time-code"
                                    class="input mt-1 text-center tracking-[0.3em]">
                                @error('twofa_code') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex gap-2">
                                <button wire:click="confirmTwoFactor" class="btn-primary">Confirm &amp; enable</button>
                                <button wire:click="cancelTwoFactorSetup" class="btn-subtle">Cancel</button>
                            </div>
                        </div>
                    </div>

                @else
                    <p class="text-13 text-subtle">Two-factor authentication is active — sign-ins require a code from your authenticator app.</p>

                    @if ($recoveryCodes !== [])
                        <div class="rounded-md border border-warning-bg bg-warning-bg/40 p-4">
                            <p class="fl mb-2 text-warning-fg">Recovery codes — store these somewhere safe</p>
                            <div class="grid grid-cols-2 gap-1">
                                @foreach ($recoveryCodes as $code)
                                    <code class="rounded bg-surface px-2 py-1 text-13 tabular-nums">{{ $code }}</code>
                                @endforeach
                            </div>
                            <p class="mt-2 text-12 text-muted">Each code signs you in once if you lose your device.</p>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($recoveryCodes === [])
                            <button wire:click="$set('showRecoveryCodes', true)" class="btn-subtle border border-line">Show recovery codes</button>
                        @endif
                        <button wire:click="regenerateRecoveryCodes" class="btn-subtle border border-line">Regenerate recovery codes</button>
                    </div>

                    <div class="border-t border-line-2 pt-4">
                        <p class="fl mb-2 text-danger-fg">Disable two-factor</p>
                        <div class="flex items-end gap-2">
                            <div class="max-w-xs flex-1">
                                <label class="fl">Confirm your password</label>
                                <input type="password" wire:model="twofa_password" autocomplete="current-password" class="input mt-1">
                                @error('twofa_password') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                            </div>
                            <button wire:click="disableTwoFactor" class="btn-danger">Disable</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- ============ Browser sessions (FR-SEC-05) ============ --}}
        <div class="rounded-md border border-line bg-surface shadow-card">
            <div class="flex h-12 items-center border-b border-line-2 px-5">
                <h2 class="text-14 font-semibold text-ink">Browser sessions</h2>
            </div>
            <div class="space-y-4 p-5">
                <p class="text-13 text-subtle">If you've signed in on a shared or lost device, sign out everywhere else. Your current session stays active.</p>
                <div class="flex items-end gap-2">
                    <div class="max-w-xs flex-1">
                        <label class="fl">Confirm your password</label>
                        <input type="password" wire:model="sessions_password" autocomplete="current-password" class="input mt-1">
                        @error('sessions_password') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <button wire:click="logoutOtherSessions" class="btn-subtle border border-line">Sign out other sessions</button>
                </div>
            </div>
        </div>
    </div>
</div>
