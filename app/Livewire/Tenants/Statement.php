<?php

declare(strict_types=1);

namespace App\Livewire\Tenants;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Services\Reporting\TenantLedger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-tenant account statement (FR-PAY-05): every invoice, payment, fine and
 * the running balance — consolidated across all the tenant's leases
 * (FR-TEN-05). This replaces the manual per-tenant ledger sheet. The ledger
 * itself is built by TenantLedger, shared with the tenant portal.
 */
#[Layout('components.layouts.app')]
class Statement extends Component
{
    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        abort_unless(
            (bool) auth()->user()?->hasPermissionTo(Permission::ViewReports->value),
            403,
        );

        $this->tenant = $tenant;
    }

    public function render(TenantLedger $ledger): View
    {
        $entries = $ledger->entries($this->tenant);

        return view('livewire.tenants.statement', [
            'entries' => $entries,
            'balance' => $ledger->balance($entries),
        ]);
    }
}
