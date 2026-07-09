<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the roles and permissions of the PRD §6.1 permission matrix.
 *
 * `✓` roles receive the base permission (and, for approval-gated actions, the
 * paired "… without approval" permission). `A` roles receive only the base
 * permission, so they may initiate the action but require approval. `–` roles
 * receive nothing for that action.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        // Flush the cache so the freshly created permissions are resolvable
        // when syncing them onto roles below.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->matrix() as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Role name => the permissions granted to it, straight from PRD §6.1.
     *
     * @return array<string, list<string>>
     */
    private function matrix(): array
    {
        return [
            RoleEnum::Administrator->value => [
                PermissionEnum::ConfigureCharges->value,
                PermissionEnum::ConfigureFineRules->value,
                PermissionEnum::IssueInvoices->value,
                PermissionEnum::ConfigureNotifications->value,
                PermissionEnum::ManageUsers->value,
                PermissionEnum::ViewReports->value,
                PermissionEnum::ViewAuditTrail->value,
            ],
            RoleEnum::FinanceOfficer->value => [
                PermissionEnum::IssueInvoices->value,
                PermissionEnum::RecordPayments->value,
                PermissionEnum::WaiveFines->value,        // A — requires approval
                PermissionEnum::ReversePayments->value,   // A — requires approval
                PermissionEnum::ViewReports->value,
            ],
            RoleEnum::LandOfficer->value => [
                PermissionEnum::ManageProperties->value,
                PermissionEnum::ManageTenants->value,
                PermissionEnum::ManageLeases->value,
                PermissionEnum::TerminateLeases->value,   // A — requires approval
                PermissionEnum::ConfigureCharges->value,
                PermissionEnum::ViewReports->value,
            ],
            RoleEnum::Supervisor->value => [
                PermissionEnum::ManageProperties->value,
                PermissionEnum::ManageTenants->value,
                PermissionEnum::ManageLeases->value,
                PermissionEnum::TerminateLeases->value,
                PermissionEnum::TerminateLeasesDirectly->value,
                PermissionEnum::ConfigureCharges->value,
                PermissionEnum::ConfigureFineRules->value,
                PermissionEnum::IssueInvoices->value,
                PermissionEnum::RecordPayments->value,
                PermissionEnum::WaiveFines->value,
                PermissionEnum::WaiveFinesDirectly->value,
                PermissionEnum::ReversePayments->value,
                PermissionEnum::ReversePaymentsDirectly->value,
                PermissionEnum::ViewReports->value,
                PermissionEnum::ViewAuditTrail->value,
            ],
            RoleEnum::Auditor->value => [
                PermissionEnum::ViewReports->value,
                PermissionEnum::ViewAuditTrail->value,
            ],
        ];
    }
}
