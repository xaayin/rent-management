<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('redirects guests from the dashboard to login', function () {
    get('/dashboard')->assertRedirect('/login');
});

it('redirects the root path to the dashboard', function () {
    get('/')->assertRedirect('/dashboard');
});

it('lets a staff user sign in via Fortify', function () {
    $user = User::factory()->create([
        'email' => 'officer@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    post('/login', [
        'email' => 'officer@example.com',
        'password' => 'secret-password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password', function () {
    User::factory()->create([
        'email' => 'officer@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    post('/login', [
        'email' => 'officer@example.com',
        'password' => 'wrong',
    ]);

    $this->assertGuest();
});

it('shows the dashboard to an authenticated staff user', function () {
    // The dashboard is gated on "view reports" (§6.1) — every role holds it.
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('auditor');

    actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard');
});
