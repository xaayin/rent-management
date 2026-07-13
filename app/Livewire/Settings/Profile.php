<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The signed-in user's own account settings: profile details, password,
 * two-factor authentication (FR-SEC-03) and browser sessions (FR-SEC-05).
 * Available to every authenticated staff member — it manages only their own
 * account, never anyone else's.
 */
#[Layout('components.layouts.app')]
class Profile extends Component
{
    // Profile details.
    public string $name = '';

    public string $email = '';

    // Password change.
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    // Two-factor authentication.
    public string $twofa_password = '';

    public string $twofa_code = '';

    public bool $showRecoveryCodes = false;

    // Browser sessions.
    public string $sessions_password = '';

    public function mount(): void
    {
        $this->name = $this->user()->name;
        $this->email = $this->user()->email;
    }

    public function updateProfile(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
        ]);

        $this->user()->update($validated);

        session()->flash('status', 'Profile updated.');
    }

    public function updatePassword(): void
    {
        $validated = $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
        ]);

        $this->user()->update(['password' => Hash::make($validated['password'])]);

        $this->reset('current_password', 'password', 'password_confirmation');
        session()->flash('status', 'Password changed.');
    }

    /**
     * Step 1: create the secret (password-confirmed per config/fortify.php)
     * and show the QR code. 2FA is not active until the code is confirmed.
     */
    public function enableTwoFactor(EnableTwoFactorAuthentication $enable): void
    {
        $this->validate(
            ['twofa_password' => ['required', 'current_password']],
            ['twofa_password.current_password' => 'Your current password is incorrect.'],
        );

        $enable($this->user());

        $this->reset('twofa_password');
    }

    /**
     * Step 2: prove the authenticator is set up by confirming a code.
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirm): void
    {
        $this->validate(['twofa_code' => ['required', 'string']]);

        try {
            $confirm($this->user(), $this->twofa_code);
        } catch (ValidationException) {
            $this->addError('twofa_code', 'The provided code is invalid — check your authenticator app and try again.');

            return;
        }

        $this->reset('twofa_code');
        $this->showRecoveryCodes = true;
        session()->flash('status', 'Two-factor authentication enabled — store your recovery codes safely.');
    }

    /**
     * Abandon an unconfirmed setup (clears the pending secret).
     */
    public function cancelTwoFactorSetup(DisableTwoFactorAuthentication $disable): void
    {
        if (! $this->user()->hasEnabledTwoFactorAuthentication()) {
            $disable($this->user());
        }

        $this->reset('twofa_code', 'twofa_password');
        $this->resetValidation();
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $generate($this->user());

        $this->showRecoveryCodes = true;
        session()->flash('status', 'New recovery codes generated — the old ones no longer work.');
    }

    public function disableTwoFactor(DisableTwoFactorAuthentication $disable): void
    {
        $this->validate(
            ['twofa_password' => ['required', 'current_password']],
            ['twofa_password.current_password' => 'Your current password is incorrect.'],
        );

        $disable($this->user());

        $this->reset('twofa_password');
        $this->showRecoveryCodes = false;
        session()->flash('status', 'Two-factor authentication disabled.');
    }

    /**
     * Invalidate every other signed-in browser/device (FR-SEC-05).
     */
    public function logoutOtherSessions(): void
    {
        $validated = $this->validate(
            ['sessions_password' => ['required', 'current_password']],
            ['sessions_password.current_password' => 'Your current password is incorrect.'],
        );

        Auth::logoutOtherDevices($validated['sessions_password']);

        $this->reset('sessions_password');
        session()->flash('status', 'All other browser sessions have been signed out.');
    }

    public function render(): View
    {
        $user = $this->user()->fresh();

        // pending = secret issued but not yet confirmed with a code.
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
        $enabled = $user->hasEnabledTwoFactorAuthentication();

        return view('livewire.settings.profile', [
            'twoFactorState' => $enabled ? 'enabled' : ($pending ? 'pending' : 'disabled'),
            'qrCodeSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'secretKey' => $pending ? decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $enabled && $this->showRecoveryCodes
                ? json_decode(decrypt($user->two_factor_recovery_codes), true)
                : [],
            'privilegedRole' => $user->hasAnyRole(['administrator', 'supervisor']),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
