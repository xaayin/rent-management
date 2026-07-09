<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The authorizable actions in the permission matrix (PRD §6.1). Backing values
 * are the permission names stored by spatie/laravel-permission and usable
 * directly with Laravel's Gate (e.g. `$user->can('manage users')`).
 *
 * Three actions are "approval-gated": the matrix marks them `A` for one role
 * (allowed, but requires supervisor approval) and `✓` for another (allowed
 * directly). Each of those has a paired "… without approval" permission —
 * roles that may act directly hold both; roles that must seek approval hold
 * only the base permission. See User::requiresApprovalFor().
 */
enum Permission: string
{
    case ManageProperties = 'manage properties';
    case ManageTenants = 'manage tenants';
    case ManageLeases = 'manage leases';
    case TerminateLeases = 'terminate leases';
    case TerminateLeasesDirectly = 'terminate leases without approval';
    case ConfigureCharges = 'configure charges';
    case ConfigureFineRules = 'configure fine rules';
    case IssueInvoices = 'issue invoices';
    case RecordPayments = 'record payments';
    case WaiveFines = 'waive fines';
    case WaiveFinesDirectly = 'waive fines without approval';
    case ReversePayments = 'reverse payments';
    case ReversePaymentsDirectly = 'reverse payments without approval';
    case ConfigureNotifications = 'configure notifications';
    case ManageUsers = 'manage users';
    case ViewReports = 'view reports';
    case ViewAuditTrail = 'view audit trail';

    /**
     * The "… without approval" permission paired with this action, or null if
     * the action is not approval-gated.
     */
    public function directVariant(): ?self
    {
        return match ($this) {
            self::TerminateLeases => self::TerminateLeasesDirectly,
            self::WaiveFines => self::WaiveFinesDirectly,
            self::ReversePayments => self::ReversePaymentsDirectly,
            default => null,
        };
    }

    public function isApprovalGated(): bool
    {
        return $this->directVariant() !== null;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
