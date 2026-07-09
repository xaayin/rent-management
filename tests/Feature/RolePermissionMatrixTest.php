<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
 | The PRD §6.1 permission matrix, transcribed independently of the seeder so
 | the tests verify the seeder rather than echo it.
 |   'yes'      => ✓  allowed directly
 |   'approval' => A  allowed, but requires supervisor approval
 |   'no'       => –  not allowed
 */
function permissionMatrix(): array
{
    return [
        'administrator' => [
            'manage properties' => 'no',
            'manage tenants' => 'no',
            'manage leases' => 'no',
            'terminate leases' => 'no',
            'configure charges' => 'yes',
            'configure fine rules' => 'yes',
            'issue invoices' => 'yes',
            'record payments' => 'no',
            'waive fines' => 'no',
            'reverse payments' => 'no',
            'configure notifications' => 'yes',
            'manage users' => 'yes',
            'view reports' => 'yes',
            'view audit trail' => 'yes',
        ],
        'finance_officer' => [
            'manage properties' => 'no',
            'manage tenants' => 'no',
            'manage leases' => 'no',
            'terminate leases' => 'no',
            'configure charges' => 'no',
            'configure fine rules' => 'no',
            'issue invoices' => 'yes',
            'record payments' => 'yes',
            'waive fines' => 'approval',
            'reverse payments' => 'approval',
            'configure notifications' => 'no',
            'manage users' => 'no',
            'view reports' => 'yes',
            'view audit trail' => 'no',
        ],
        'land_officer' => [
            'manage properties' => 'yes',
            'manage tenants' => 'yes',
            'manage leases' => 'yes',
            'terminate leases' => 'approval',
            'configure charges' => 'yes',
            'configure fine rules' => 'no',
            'issue invoices' => 'no',
            'record payments' => 'no',
            'waive fines' => 'no',
            'reverse payments' => 'no',
            'configure notifications' => 'no',
            'manage users' => 'no',
            'view reports' => 'yes',
            'view audit trail' => 'no',
        ],
        'supervisor' => [
            'manage properties' => 'yes',
            'manage tenants' => 'yes',
            'manage leases' => 'yes',
            'terminate leases' => 'yes',
            'configure charges' => 'yes',
            'configure fine rules' => 'yes',
            'issue invoices' => 'yes',
            'record payments' => 'yes',
            'waive fines' => 'yes',
            'reverse payments' => 'yes',
            'configure notifications' => 'no',
            'manage users' => 'no',
            'view reports' => 'yes',
            'view audit trail' => 'yes',
        ],
        'auditor' => [
            'manage properties' => 'no',
            'manage tenants' => 'no',
            'manage leases' => 'no',
            'terminate leases' => 'no',
            'configure charges' => 'no',
            'configure fine rules' => 'no',
            'issue invoices' => 'no',
            'record payments' => 'no',
            'waive fines' => 'no',
            'reverse payments' => 'no',
            'configure notifications' => 'no',
            'manage users' => 'no',
            'view reports' => 'yes',
            'view audit trail' => 'yes',
        ],
    ];
}

dataset('matrix cells', function () {
    $cells = [];

    foreach (permissionMatrix() as $role => $actions) {
        foreach ($actions as $action => $expectation) {
            $cells["{$role} → {$action} = {$expectation}"] = [$role, $action, $expectation];
        }
    }

    return $cells;
});

it('enforces every cell of the PRD §6.1 matrix', function (string $role, string $action, string $expectation) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $permission = Permission::from($action);

    match ($expectation) {
        'no' => expect($user->mayInitiate($permission))->toBeFalse()
            ->and($user->mayActWithoutApproval($permission))->toBeFalse()
            ->and($user->requiresApprovalFor($permission))->toBeFalse(),

        'yes' => expect($user->mayInitiate($permission))->toBeTrue()
            ->and($user->mayActWithoutApproval($permission))->toBeTrue()
            ->and($user->requiresApprovalFor($permission))->toBeFalse(),

        'approval' => expect($user->mayInitiate($permission))->toBeTrue()
            ->and($user->mayActWithoutApproval($permission))->toBeFalse()
            ->and($user->requiresApprovalFor($permission))->toBeTrue(),
    };
})->with('matrix cells');

it('exposes permissions through Laravel\'s Gate', function () {
    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');

    $auditor = User::factory()->create();
    $auditor->assignRole('auditor');

    expect($supervisor->can('terminate leases'))->toBeTrue()
        ->and($supervisor->can('terminate leases without approval'))->toBeTrue()
        ->and($auditor->can('view audit trail'))->toBeTrue()
        ->and($auditor->can('terminate leases'))->toBeFalse();
});

it('only lets the supervisor act directly on the approval-gated actions', function (string $action) {
    $permission = Permission::from($action);

    foreach (['administrator', 'finance_officer', 'land_officer', 'auditor'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);
        expect($user->mayActWithoutApproval($permission))->toBeFalse();
    }

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    expect($supervisor->mayActWithoutApproval($permission))->toBeTrue();
})->with(['terminate leases', 'waive fines', 'reverse payments']);
