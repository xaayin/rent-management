<?php

declare(strict_types=1);

use App\Livewire\Settings\UserManagement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('blocks a non-admin from the user management route', function () {
    actingAs(userWithRole('finance_officer'))
        ->get('/settings/users')
        ->assertForbidden();
});

it('lets an administrator open the user management screen', function () {
    actingAs(userWithRole('administrator'))
        ->get('/settings/users')
        ->assertOk()
        ->assertSee('Users & roles');
});

it('forbids a non-admin from mounting the component even without the route guard', function () {
    actingAs(userWithRole('auditor'));

    Livewire::test(UserManagement::class)->assertForbidden();
});

it('lets an administrator create a staff user with a role', function () {
    actingAs(userWithRole('administrator'));

    Livewire::test(UserManagement::class)
        ->set('name', 'Fathimath Ali')
        ->set('email', 'fathimath@example.com')
        ->set('password', 'secret-password')
        ->set('role', 'finance_officer')
        ->call('createUser')
        ->assertHasNoErrors();

    $created = User::where('email', 'fathimath@example.com')->first();

    expect($created)->not->toBeNull()
        ->and($created->hasRole('finance_officer'))->toBeTrue();
});

it('records an audit-trail entry when a user is created', function () {
    actingAs(userWithRole('administrator'));

    Livewire::test(UserManagement::class)
        ->set('name', 'Ibrahim')
        ->set('email', 'ibrahim@example.com')
        ->set('password', 'secret-password')
        ->set('role', 'land_officer')
        ->call('createUser')
        ->assertHasNoErrors();

    expect(Activity::where('log_name', 'user')->where('description', 'Created staff user')->exists())
        ->toBeTrue();
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    actingAs(userWithRole('administrator'));

    Livewire::test(UserManagement::class)
        ->set('name', 'Someone')
        ->set('email', 'taken@example.com')
        ->set('password', 'secret-password')
        ->set('role', 'auditor')
        ->call('createUser')
        ->assertHasErrors(['email']);
});

it('lets an administrator change a user\'s role', function () {
    actingAs(userWithRole('administrator'));
    $target = userWithRole('land_officer');

    Livewire::test(UserManagement::class)
        ->call('updateRole', $target->id, 'supervisor');

    expect($target->fresh()->hasRole('supervisor'))->toBeTrue()
        ->and($target->fresh()->hasRole('land_officer'))->toBeFalse();
});

it('authorizes user management server-side through the policy', function () {
    $admin = userWithRole('administrator');
    $finance = userWithRole('finance_officer');

    expect($admin->can('viewAny', User::class))->toBeTrue()
        ->and($admin->can('create', User::class))->toBeTrue()
        ->and($finance->can('viewAny', User::class))->toBeFalse()
        ->and($finance->can('create', User::class))->toBeFalse();
});
