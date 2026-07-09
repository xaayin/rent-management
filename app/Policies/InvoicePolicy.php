<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Generating/issuing invoices is granted to Administrators, Finance Officers
 * and Supervisors (PRD §6.1 "Generate / issue invoices").
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::IssueInvoices->value);
    }

    public function generate(User $user): bool
    {
        return $user->hasPermissionTo(Permission::IssueInvoices->value);
    }
}
