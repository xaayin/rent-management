<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\ReminderKind;
use App\Livewire\Leases\Index as LeasesIndex;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Sms\SmsSender;
use Carbon\CarbonImmutable;
use Database\Seeders\ReminderRulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Tests\Support\FakeSmsSender;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ReminderRulesSeeder::class);

    $this->app->instance(SmsSender::class, new FakeSmsSender);
});

function workspaceUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * An active flat MVR 500 lease with a January 2026 invoice, marked overdue.
 */
function overdueLease(): array
{
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));
    artisan('invoices:refresh-fines', ['--as-of' => '2026-02-01']);

    return [$lease, $invoice->fresh()];
}

it('opens the lease slide-over with details and actions', function () {
    [$lease] = overdueLease();

    actingAs(workspaceUser('supervisor'));

    Livewire::test(LeasesIndex::class)
        ->call('selectLease', $lease->id)
        ->assertSee($lease->agreement_number)
        ->assertSee('Overdue')
        ->assertSee('Record payment')
        ->assertSee('Send reminder')
        ->assertSee('Recent invoices');
});

it('records a payment through the workspace modal', function () {
    [$lease, $invoice] = overdueLease();

    actingAs(workspaceUser('supervisor'));

    Livewire::test(LeasesIndex::class)
        ->call('selectLease', $lease->id)
        ->call('startPayment', $invoice->id)
        ->set('pay_amount', '500.00')
        ->set('pay_date', '2026-01-10')
        ->set('pay_method', 'cash')
        ->call('confirmPayment')
        ->assertHasNoErrors();

    // Paid on the due date → fine recomputed to zero, invoice settled.
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('sends a manual reminder from the workspace', function () {
    [$lease, $invoice] = overdueLease();

    actingAs(workspaceUser('supervisor'));

    Livewire::test(LeasesIndex::class)
        ->call('selectLease', $lease->id)
        ->call('sendReminder', $invoice->id)
        ->assertHasNoErrors();

    expect(NotificationLog::where('kind', ReminderKind::Manual->value)->count())->toBe(1);
});

it('filters leases by the overdue tab', function () {
    [$overdueLease] = overdueLease();
    $cleanLease = Lease::factory()->active()->create(['agreement_number' => 'AG-CLEAN/2026']);

    actingAs(workspaceUser('land_officer'));

    Livewire::test(LeasesIndex::class)
        ->set('tab', 'overdue')
        ->assertSee($overdueLease->agreement_number)
        ->assertDontSee('AG-CLEAN/2026');
});

it('filters leases by search text', function () {
    $lease = Lease::factory()->active()->create();
    $other = Lease::factory()->create(['agreement_number' => 'AG-ELSEWHERE/2026']);

    actingAs(workspaceUser('land_officer'));

    Livewire::test(LeasesIndex::class)
        ->set('q', $lease->tenant->name)
        ->assertSee($lease->agreement_number)
        ->assertDontSee('AG-ELSEWHERE/2026');
});

it('filters by the status and tenant-type chips', function () {
    $draft = Lease::factory()->create(['agreement_number' => 'AG-DRAFT/2026']);
    $active = Lease::factory()->active()->create(['agreement_number' => 'AG-ACTIVE/2026']);
    $orgLease = Lease::factory()->active()->create(['agreement_number' => 'AG-ORG/2026']);
    $orgLease->tenant->update([
        'type' => 'organisation',
        'national_id' => null,
        'company_reg_no' => 'C-777/2020',
    ]);

    actingAs(workspaceUser('land_officer'));

    Livewire::test(LeasesIndex::class)
        ->set('statusFilter', 'draft')
        ->assertSee('AG-DRAFT/2026')
        ->assertDontSee('AG-ACTIVE/2026')
        ->set('statusFilter', '')
        ->set('tenantTypeFilter', 'organisation')
        ->assertSee('AG-ORG/2026')
        ->assertDontSee('AG-DRAFT/2026')
        ->call('clearFilters')
        ->assertSee('AG-DRAFT/2026')
        ->assertSee('AG-ORG/2026');
});

it('filters by the property-type chip', function () {
    $antenna = Lease::factory()->active()->create(['agreement_number' => 'AG-ANT/2026']);
    $antenna->property->update(['usage_type' => 'telecom_antenna']);
    $shop = Lease::factory()->active()->create(['agreement_number' => 'AG-SHOP/2026']);
    $shop->property->update(['usage_type' => 'commercial']);

    actingAs(workspaceUser('land_officer'));

    Livewire::test(LeasesIndex::class)
        ->set('propertyTypeFilter', 'telecom_antenna')
        ->assertSee('AG-ANT/2026')
        ->assertDontSee('AG-SHOP/2026');
});

it('paginates the list ten per page and resets the page when a filter changes', function () {
    foreach (range(1, 12) as $i) {
        Lease::factory()->create(['agreement_number' => sprintf('AG-PAGE-%02d/2026', $i)]);
    }

    actingAs(workspaceUser('land_officer'));

    $component = Livewire::test(LeasesIndex::class)
        ->assertSee('Showing 1–10 of 12')
        ->assertSee('AG-PAGE-12/2026')       // newest first
        ->assertDontSee('AG-PAGE-01/2026')
        ->call('gotoPage', 2)
        ->assertSee('Showing 11–12 of 12')
        ->assertSee('AG-PAGE-01/2026')       // the two oldest
        ->assertDontSee('AG-PAGE-12/2026');

    // Changing a filter jumps back to page 1.
    $component->set('statusFilter', 'draft')
        ->assertSee('Showing 1–10 of 12')
        ->assertSee('AG-PAGE-12/2026');
});

it('opens the create form from the top-bar Create menu deep link', function () {
    actingAs(workspaceUser('land_officer'))
        ->get('/leases?create=1')
        ->assertOk()
        ->assertSee('Add a lease');
});

it('keeps registry actions hidden from unpermitted roles in the workspace', function () {
    [$lease] = overdueLease();

    // Land officers manage leases but may not record payments or send reminders.
    actingAs(workspaceUser('land_officer'));

    Livewire::test(LeasesIndex::class)
        ->call('selectLease', $lease->id)
        ->assertSee($lease->agreement_number)
        ->assertDontSee('Record payment')
        ->assertDontSee('Send reminder');
});
