<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransferClaimStatus;
use App\Exceptions\InvalidTransferClaimException;
use App\Livewire\Portal\Home;
use App\Livewire\Transfers\Index;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\TransferClaim;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Portal\TransferClaimService;
use App\Services\Sms\SmsSender;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ReminderRulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSmsSender;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ReminderRulesSeeder::class);

    $this->sms = new FakeSmsSender;
    $this->app->instance(SmsSender::class, $this->sms);
});

function claims(): TransferClaimService
{
    return app(TransferClaimService::class);
}

function financeUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('finance_officer');

    return $user;
}

/**
 * A tenant with `$count` MVR 500 invoices outstanding (Jan 2026 onward).
 *
 * @return array{0: Tenant, 1: Collection<int, Invoice>}
 */
function claimTenant(int $count = 2): array
{
    $tenant = Tenant::factory()->create(['mobile' => '7771234']);
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    $invoices = collect(range(0, $count - 1))->map(
        fn (int $i) => app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01')->addMonths($i)),
    );

    return [$tenant, $invoices];
}

/*
|--------------------------------------------------------------------------
| Submitting a claim (tenant side)
|--------------------------------------------------------------------------
*/

it('files a pending claim without moving any money', function () {
    [$tenant] = claimTenant();

    $claim = claims()->submit($tenant, Money::fromRufiyaa('1000.00'), CarbonImmutable::parse('2026-01-08'), 'BML-88421', 'Paid from Malé.');

    expect($claim->status)->toBe(TransferClaimStatus::Pending)
        ->and($claim->amount()->format())->toBe('MVR 1,000.00')
        ->and($claim->bank_reference)->toBe('BML-88421')
        ->and($tenant->outstandingBalance()->format())->toBe('MVR 1,000.00');   // untouched
});

it('rejects a non-positive claim amount', function () {
    [$tenant] = claimTenant();

    expect(fn () => claims()->submit($tenant, Money::fromLaari(0), CarbonImmutable::now(), 'X'))
        ->toThrow(InvalidTransferClaimException::class);
});

it('allows only one pending claim per tenant at a time', function () {
    [$tenant] = claimTenant();

    claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-1');

    expect(fn () => claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-2'))
        ->toThrow(InvalidTransferClaimException::class)
        ->and(TransferClaim::pending()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Confirming a claim (Finance side) — this is where money moves
|--------------------------------------------------------------------------
*/

it('confirms a claim into a real receipt, settling invoices oldest-first', function () {
    [$tenant, $invoices] = claimTenant(2);
    $finance = financeUser();

    $claim = claims()->submit($tenant, Money::fromRufiyaa('1000.00'), CarbonImmutable::parse('2026-01-08'), 'BML-88421');

    $receipt = claims()->confirm($claim, $finance);
    $claim->refresh();

    expect($claim->status)->toBe(TransferClaimStatus::Confirmed)
        ->and($claim->receipt_id)->toBe($receipt->id)
        ->and($claim->decided_by)->toBe($finance->id)
        ->and($receipt->payments)->toHaveCount(2)
        ->and($receipt->payments->first()->method)->toBe(PaymentMethod::BankTransfer)
        ->and($receipt->payments->first()->reference)->toBe('BML-88421')
        ->and($invoices[0]->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoices[1]->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('confirms on the transfer date the tenant stated, so fines are computed then', function () {
    [$tenant, $invoices] = claimTenant(1);
    $finance = financeUser();

    $claim = claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-1');
    $receipt = claims()->confirm($claim, $finance);

    expect($receipt->payments->first()->payment_date->toDateString())->toBe('2026-01-08');
});

it('texts the tenant a payment confirmation on confirm', function () {
    [$tenant] = claimTenant(1);

    $claim = claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-1');
    claims()->confirm($claim, financeUser());

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['message'])->toContain('payment received');
});

it('blocks confirming a claim that exceeds what the tenant now owes', function () {
    [$tenant, $invoices] = claimTenant(1);   // owes MVR 500
    $finance = financeUser();

    // Tenant over-claims (or paid part of it directly since).
    $claim = claims()->submit($tenant, Money::fromRufiyaa('900.00'), CarbonImmutable::parse('2026-01-08'), 'BML-1');

    expect(fn () => claims()->confirm($claim, $finance))
        ->toThrow(InvalidTransferClaimException::class)
        // Nothing recorded; the claim stays pending for the clerk to reject or fix.
        ->and($claim->fresh()->isPending())->toBeTrue()
        ->and($invoices[0]->fresh()->status)->not->toBe(InvoiceStatus::Paid);
});

it('cannot confirm an already-decided claim', function () {
    [$tenant] = claimTenant(1);
    $finance = financeUser();

    $claim = claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-1');
    claims()->confirm($claim, $finance);

    expect(fn () => claims()->confirm($claim->fresh(), $finance))
        ->toThrow(InvalidTransferClaimException::class);
});

/*
|--------------------------------------------------------------------------
| Rejecting a claim
|--------------------------------------------------------------------------
*/

it('rejects a claim with a reason and moves no money', function () {
    [$tenant, $invoices] = claimTenant(1);
    $finance = financeUser();

    $claim = claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-BOGUS');

    claims()->reject($claim, $finance, 'No transfer found against this reference.');
    $claim->refresh();

    expect($claim->status)->toBe(TransferClaimStatus::Rejected)
        ->and($claim->decision_note)->toBe('No transfer found against this reference.')
        ->and($claim->receipt_id)->toBeNull()
        ->and($invoices[0]->fresh()->status)->not->toBe(InvoiceStatus::Paid);
});

it('requires the tenant to be able to file again after a decision', function () {
    [$tenant] = claimTenant(1);

    $first = claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-1');
    claims()->reject($first, financeUser(), 'Wrong reference.');

    $second = claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-2');

    expect($second->isPending())->toBeTrue()
        ->and(TransferClaim::count())->toBe(2);
});

it('audits both the submission and the decision', function () {
    [$tenant] = claimTenant(1);

    $claim = claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-1');
    claims()->confirm($claim, financeUser());

    expect(Activity::where('log_name', 'transfer_claim')->count())->toBeGreaterThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| Tenant portal — submitting a claim
|--------------------------------------------------------------------------
*/

it('lets a tenant submit a transfer from the portal', function () {
    [$tenant] = claimTenant(2);
    Auth::guard('tenant')->login($tenant);

    Livewire::test(Home::class)
        ->call('setTab', 'pay')
        ->set('claim_amount', '1000.00')
        ->set('claim_date', '2026-01-08')
        ->set('claim_reference', 'BML-88421')
        ->call('submitClaim')
        ->assertHasNoErrors();

    expect(TransferClaim::where('tenant_id', $tenant->id)->pending()->count())->toBe(1);
});

it('rejects a future transfer date from the portal', function () {
    [$tenant] = claimTenant(1);
    Auth::guard('tenant')->login($tenant);

    Livewire::test(Home::class)
        ->set('claim_amount', '500.00')
        ->set('claim_date', now()->addDay()->toDateString())
        ->set('claim_reference', 'BML-1')
        ->call('submitClaim')
        ->assertHasErrors('claim_date');
});

/*
|--------------------------------------------------------------------------
| Staff queue — §6.1 and the confirm/reject actions
|--------------------------------------------------------------------------
*/

it('opens the transfers queue to Finance and Supervisor, not others', function (string $role, bool $allowed) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $response = \Pest\Laravel\actingAs($user)->get('/transfers');

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    ['finance_officer', true],
    ['supervisor', true],
    ['land_officer', false],
    ['administrator', false],   // §6.1: Admin does not record payments
    ['auditor', false],
]);

it('confirms a claim from the queue, issuing a receipt', function () {
    [$tenant, $invoices] = claimTenant(2);
    $claim = claims()->submit($tenant, Money::fromRufiyaa('1000.00'), CarbonImmutable::parse('2026-01-08'), 'BML-1');

    \Pest\Laravel\actingAs(financeUser());

    Livewire::test(Index::class)
        ->call('confirm', $claim->id)
        ->assertHasNoErrors();

    expect($claim->fresh()->status)->toBe(TransferClaimStatus::Confirmed)
        ->and($invoices[0]->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('requires a reason to reject from the queue', function () {
    [$tenant] = claimTenant(1);
    $claim = claims()->submit($tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-08'), 'BML-1');

    \Pest\Laravel\actingAs(financeUser());

    Livewire::test(Index::class)
        ->call('startReject', $claim->id)
        ->call('confirmReject')
        ->assertHasErrors('decision_note');

    expect($claim->fresh()->isPending())->toBeTrue();
});
