<?php

declare(strict_types=1);

use App\Livewire\Settings\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

function profileUser(): User
{
    return User::factory()->create(['password' => Hash::make('secret-password')]);
}

it('opens my account from any signed-in user', function () {
    actingAs(profileUser())
        ->get('/settings/profile')
        ->assertOk()
        ->assertSee('My account')
        ->assertSee('Two-factor authentication')
        ->assertSee('Browser sessions');
});

it('updates the profile details and rejects a taken email', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = profileUser();
    actingAs($user);

    Livewire::test(Profile::class)
        ->set('name', 'Aishath Rasheed')
        ->set('email', 'aishath@example.com')
        ->call('updateProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Aishath Rasheed')
        ->and($user->fresh()->email)->toBe('aishath@example.com');

    Livewire::test(Profile::class)
        ->set('email', 'taken@example.com')
        ->call('updateProfile')
        ->assertHasErrors(['email']);
});

it('changes the password only with the correct current password', function () {
    $user = profileUser();
    actingAs($user);

    Livewire::test(Profile::class)
        ->set('current_password', 'wrong')
        ->set('password', 'new-secret-password')
        ->set('password_confirmation', 'new-secret-password')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);

    Livewire::test(Profile::class)
        ->set('current_password', 'secret-password')
        ->set('password', 'new-secret-password')
        ->set('password_confirmation', 'new-secret-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue();
});

it('walks the full two-factor lifecycle: enable, confirm, recovery codes, disable', function () {
    $user = profileUser();
    actingAs($user);

    // Enable requires the password; a wrong one is rejected.
    Livewire::test(Profile::class)
        ->set('twofa_password', 'nope')
        ->call('enableTwoFactor')
        ->assertHasErrors(['twofa_password']);

    $component = Livewire::test(Profile::class)
        ->set('twofa_password', 'secret-password')
        ->call('enableTwoFactor')
        ->assertHasNoErrors()
        ->assertSee('Scan the QR code');

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();

    // A wrong code does not confirm.
    $component->set('twofa_code', '000000')
        ->call('confirmTwoFactor')
        ->assertHasErrors(['twofa_code']);

    // The real TOTP for the issued secret confirms and reveals recovery codes.
    $otp = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));

    $component->set('twofa_code', $otp)
        ->call('confirmTwoFactor')
        ->assertHasNoErrors()
        ->assertSee('Recovery codes');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    // Regenerating replaces the codes.
    $before = $user->fresh()->two_factor_recovery_codes;
    $component->call('regenerateRecoveryCodes');
    expect($user->fresh()->two_factor_recovery_codes)->not->toBe($before);

    // Disable requires the password and clears everything.
    $component->set('twofa_password', 'secret-password')
        ->call('disableTwoFactor')
        ->assertHasNoErrors();

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('challenges a two-factor user at login and accepts the TOTP code', function () {
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();

    $user = User::factory()->create([
        'password' => Hash::make('secret-password'),
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['RECOVERY-CODE-ONE', 'RECOVERY-CODE-TWO'])),
        'two_factor_confirmed_at' => now(),
    ]);

    // Login diverts to the challenge instead of signing in.
    post('/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->get('/two-factor-challenge')->assertOk()->assertSee('Two-factor authentication');

    post('/two-factor-challenge', ['code' => $google2fa->getCurrentOtp($secret)])
        ->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

it('accepts a recovery code at the challenge', function () {
    $secret = app(Google2FA::class)->generateSecretKey();

    $user = User::factory()->create([
        'password' => Hash::make('secret-password'),
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['RESCUE-ME-12345', 'OTHER-CODE-6789'])),
        'two_factor_confirmed_at' => now(),
    ]);

    post('/login', ['email' => $user->email, 'password' => 'secret-password']);

    post('/two-factor-challenge', ['recovery_code' => 'RESCUE-ME-12345'])
        ->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);

    // A used recovery code is consumed.
    expect(decrypt($user->fresh()->two_factor_recovery_codes))->not->toContain('RESCUE-ME-12345');
});

it('signs out other browser sessions with the correct password', function () {
    $user = profileUser();
    actingAs($user);

    Livewire::test(Profile::class)
        ->set('sessions_password', 'wrong')
        ->call('logoutOtherSessions')
        ->assertHasErrors(['sessions_password']);

    Livewire::test(Profile::class)
        ->set('sessions_password', 'secret-password')
        ->call('logoutOtherSessions')
        ->assertHasNoErrors();

    // The current session survives.
    $this->assertAuthenticatedAs($user);
});
