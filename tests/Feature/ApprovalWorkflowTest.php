<?php

declare(strict_types=1);

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidApprovalException;
use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Livewire\Leases\Index as LeasesIndex;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function approvalUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function approvals(): ApprovalService
{
    return app(ApprovalService::class);
}

/**
 * A flat MVR 500/month active lease.
 */
function approvableLease(): Lease
{
    return Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);
}

/**
 * A fully-paid January 2026 invoice, and the MVR 500 payment settling it.
 */
function approvablePayment(): array
{
    $lease = approvableLease();
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    $payment = app(PaymentRecorder::class)->record(
        $invoice,
        Money::fromLaari(50_000),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    );

    return [$invoice->fresh(), $payment];
}

it('files a pending request instead of acting, for a role that needs approval', function () {
    $lease = approvableLease();
    $landOfficer = approvalUser('land_officer');

    $request = approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'Tenant vacated.');

    expect($request->status)->toBe(ApprovalStatus::Pending)
        ->and($request->requested_by)->toBe($landOfficer->id)
        ->and($request->subject->is($lease))->toBeTrue()
        ->and($request->reason)->toBe('Tenant vacated.')
        // The lease is untouched until a supervisor says so.
        ->and($lease->fresh()->status)->toBe(LeaseStatus::Active);
});

it('refuses a request from a user who may already act directly', function () {
    $lease = approvableLease();

    expect(fn () => approvals()->request(
        approvalUser('supervisor'), ApprovalAction::TerminateLease, $lease, 'Why ask myself.'
    ))->toThrow(InvalidApprovalException::class);
});

it('refuses a request from a user without the base permission', function () {
    $lease = approvableLease();

    // Finance may not terminate leases at all (§6.1).
    expect(fn () => approvals()->request(
        approvalUser('finance_officer'), ApprovalAction::TerminateLease, $lease, 'Not my job.'
    ))->toThrow(InvalidApprovalException::class);
});

it('allows only one open request per subject and action', function () {
    $lease = approvableLease();
    $landOfficer = approvalUser('land_officer');

    approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'First.');

    expect(fn () => approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'Second.'))
        ->toThrow(InvalidApprovalException::class)
        ->and(ApprovalRequest::pending()->count())->toBe(1);
});

it('lets a new request be filed once the previous one is decided', function () {
    $lease = approvableLease();
    $landOfficer = approvalUser('land_officer');
    $supervisor = approvalUser('supervisor');

    $first = approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'Mistake.');
    approvals()->reject($first, $supervisor, 'Not yet — tenant is disputing.');

    // The rejected row released the pending key, so a fresh attempt is allowed.
    $second = approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'Dispute resolved.');

    expect($second->isPending())->toBeTrue()
        ->and(ApprovalRequest::count())->toBe(2);
});

it('terminates the lease when a supervisor approves, using the requested reason', function () {
    $lease = approvableLease();
    $landOfficer = approvalUser('land_officer');
    $supervisor = approvalUser('supervisor');

    $request = approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'Tenant vacated.');
    approvals()->approve($request, $supervisor);

    $lease->refresh();
    $request->refresh();

    expect($lease->status)->toBe(LeaseStatus::Terminated)
        ->and($lease->termination_reason)->toBe('Tenant vacated.')
        ->and($request->status)->toBe(ApprovalStatus::Approved)
        ->and($request->decided_by)->toBe($supervisor->id)
        ->and($request->decided_at)->not->toBeNull()
        ->and($request->pending_key)->toBeNull();
});

it('does not act when a supervisor rejects, and records why', function () {
    $lease = approvableLease();
    $request = approvals()->request(approvalUser('land_officer'), ApprovalAction::TerminateLease, $lease, 'Vacated.');

    approvals()->reject($request, approvalUser('supervisor'), 'Lease has 3 months left to run.');

    $request->refresh();

    expect($lease->fresh()->status)->toBe(LeaseStatus::Active)
        ->and($request->status)->toBe(ApprovalStatus::Rejected)
        ->and($request->decision_note)->toBe('Lease has 3 months left to run.')
        ->and($request->pending_key)->toBeNull();
});

it('reverses the payment when a supervisor approves (FR-PAY-06)', function () {
    [$invoice, $payment] = approvablePayment();
    $finance = approvalUser('finance_officer');
    $supervisor = approvalUser('supervisor');

    expect($invoice->status)->toBe(InvoiceStatus::Paid);

    $request = approvals()->request($finance, ApprovalAction::ReversePayment, $payment, 'Cheque bounced.');

    // Nothing moves while it is pending.
    expect($payment->fresh()->isReversed())->toBeFalse()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    approvals()->approve($request, $supervisor);

    $invoice->refresh();
    $reversal = Payment::where('reversed_payment_id', $payment->id)->sole();

    expect($payment->fresh()->isReversed())->toBeTrue()
        ->and($reversal->amount_laari)->toBe(-50_000)
        ->and($reversal->reversal_reason)->toBe('Cheque bounced.')
        ->and($invoice->outstandingPrincipalLaari())->toBe(50_000);
});

it('never applies an approved action twice', function () {
    $lease = approvableLease();
    $supervisor = approvalUser('supervisor');
    $request = approvals()->request(approvalUser('land_officer'), ApprovalAction::TerminateLease, $lease, 'Vacated.');

    approvals()->approve($request, $supervisor);

    expect(fn () => approvals()->approve($request->fresh(), $supervisor))
        ->toThrow(InvalidApprovalException::class);
});

it('cannot decide a request that was already rejected or cancelled', function () {
    $lease = approvableLease();
    $landOfficer = approvalUser('land_officer');
    $supervisor = approvalUser('supervisor');

    $request = approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'Vacated.');
    approvals()->cancel($request, $landOfficer);

    expect($request->fresh()->status)->toBe(ApprovalStatus::Cancelled)
        ->and(fn () => approvals()->approve($request->fresh(), $supervisor))
        ->toThrow(InvalidApprovalException::class)
        ->and($lease->fresh()->status)->toBe(LeaseStatus::Active);
});

it('refuses a decision from someone who cannot perform the action directly', function () {
    $lease = approvableLease();
    $landOfficer = approvalUser('land_officer');
    $request = approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'Vacated.');

    // A requester must not be able to wave their own request through.
    expect(fn () => approvals()->approve($request, $landOfficer))
        ->toThrow(InvalidApprovalException::class)
        ->and(fn () => approvals()->approve($request, approvalUser('administrator')))
        ->toThrow(InvalidApprovalException::class)
        ->and($lease->fresh()->status)->toBe(LeaseStatus::Active);
});

it('only lets the requester cancel their own request', function () {
    $lease = approvableLease();
    $request = approvals()->request(approvalUser('land_officer'), ApprovalAction::TerminateLease, $lease, 'Vacated.');

    expect(fn () => approvals()->cancel($request, approvalUser('land_officer')))
        ->toThrow(InvalidApprovalException::class);
});

/**
 * The window between request and decision is exactly where reality drifts: a
 * supervisor terminates the lease themselves, or reverses the payment, while
 * the request sits in the queue. Approving then must not double-apply.
 */
it('fails cleanly when the lease was already terminated by someone else', function () {
    $lease = approvableLease();
    $request = approvals()->request(approvalUser('land_officer'), ApprovalAction::TerminateLease, $lease, 'Vacated.');

    $lease->terminate('Handled directly by the supervisor.');

    expect(fn () => approvals()->approve($request, approvalUser('supervisor')))
        ->toThrow(InvalidApprovalException::class)
        // The earlier, direct termination stands untouched.
        ->and($lease->fresh()->termination_reason)->toBe('Handled directly by the supervisor.')
        ->and($request->fresh()->isPending())->toBeTrue();
});

it('fails cleanly when the payment was already reversed by someone else', function () {
    [, $payment] = approvablePayment();
    $supervisor = approvalUser('supervisor');

    $request = approvals()->request(approvalUser('finance_officer'), ApprovalAction::ReversePayment, $payment, 'Bounced.');

    app(PaymentRecorder::class)->reverse($payment, 'Reversed directly.', CarbonImmutable::parse('2026-01-06'));

    expect(fn () => approvals()->approve($request, $supervisor))
        ->toThrow(InvalidApprovalException::class)
        // Exactly one reversal exists — the direct one.
        ->and(Payment::where('reversed_payment_id', $payment->id)->count())->toBe(1);
});

it('audits the request and the decision with both users', function () {
    $lease = approvableLease();
    $landOfficer = approvalUser('land_officer');
    $supervisor = approvalUser('supervisor');

    $request = approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'Vacated.');
    approvals()->approve($request, $supervisor);

    expect(Activity::where('log_name', 'approval')->count())->toBeGreaterThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| The approvals inbox screen
|--------------------------------------------------------------------------
*/

it('shows the inbox only to users who can decide something', function (string $role, bool $allowed) {
    actingAs(approvalUser($role));

    $response = get('/approvals');

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    ['supervisor', true],
    ['land_officer', false],   // may request, but never decides
    ['finance_officer', false],
    ['administrator', false],
    ['auditor', false],
]);

it('lists only requests the viewer is entitled to decide', function () {
    $lease = approvableLease();
    [, $payment] = approvablePayment();

    approvals()->request(approvalUser('land_officer'), ApprovalAction::TerminateLease, $lease, 'Vacated.');
    approvals()->request(approvalUser('finance_officer'), ApprovalAction::ReversePayment, $payment, 'Bounced.');

    // The Supervisor holds both "without approval" variants, so sees both.
    actingAs(approvalUser('supervisor'));

    Livewire::test(ApprovalsIndex::class)
        ->assertSee('Terminate lease')
        ->assertSee('Reverse payment')
        ->assertSee('Vacated.')
        ->assertSee('Bounced.');
});

it('approves from the screen and carries the action out', function () {
    $lease = approvableLease();
    $request = approvals()->request(approvalUser('land_officer'), ApprovalAction::TerminateLease, $lease, 'Vacated.');

    actingAs(approvalUser('supervisor'));

    Livewire::test(ApprovalsIndex::class)
        ->call('approve', $request->id)
        ->assertHasNoErrors();

    expect($lease->fresh()->status)->toBe(LeaseStatus::Terminated);
});

it('requires a reason to reject from the screen', function () {
    $lease = approvableLease();
    $request = approvals()->request(approvalUser('land_officer'), ApprovalAction::TerminateLease, $lease, 'Vacated.');

    actingAs(approvalUser('supervisor'));

    Livewire::test(ApprovalsIndex::class)
        ->call('startReject', $request->id)
        ->call('confirmReject')
        ->assertHasErrors(['decision_note']);

    expect($request->fresh()->isPending())->toBeTrue();
});

it('does not let a land officer approve — at the door or at the policy', function () {
    $lease = approvableLease();
    $requester = approvalUser('land_officer');
    $request = approvals()->request($requester, ApprovalAction::TerminateLease, $lease, 'Vacated.');

    $colleague = approvalUser('land_officer');

    // The inbox refuses to open for them at all...
    actingAs($colleague);
    Livewire::test(ApprovalsIndex::class)->assertForbidden();

    // ...and the decision itself is refused, so there is no way through even if
    // the screen were reachable. Neither a colleague nor the requester decides.
    expect($colleague->can('decide', $request))->toBeFalse()
        ->and($requester->can('decide', $request))->toBeFalse()
        ->and($lease->fresh()->status)->toBe(LeaseStatus::Active);
});

it('routes a land officer termination into the queue instead of terminating', function () {
    $lease = approvableLease();

    actingAs(approvalUser('land_officer'));

    Livewire::test(LeasesIndex::class)
        ->call('selectLease', $lease->id)
        ->call('startTerminate', $lease->id)
        ->set('termination_reason', 'Tenant vacated the premises.')
        ->call('confirmTerminate')
        ->assertHasNoErrors();

    expect($lease->fresh()->status)->toBe(LeaseStatus::Active)
        ->and(ApprovalRequest::pending()->count())->toBe(1);
});

it('lets the requester withdraw their own request but not a colleague’s', function () {
    $lease = approvableLease();
    $landOfficer = approvalUser('land_officer');
    $request = approvals()->request($landOfficer, ApprovalAction::TerminateLease, $lease, 'Vacated.');

    actingAs(approvalUser('land_officer'));   // a different officer
    Livewire::test(LeasesIndex::class)
        ->call('selectLease', $lease->id)
        ->call('withdrawTermination', $request->id)
        ->assertForbidden();

    actingAs($landOfficer);
    Livewire::test(LeasesIndex::class)
        ->call('selectLease', $lease->id)
        ->call('withdrawTermination', $request->id)
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(ApprovalStatus::Cancelled);
});

/**
 * The approvals badge renders in the app layout, so these helpers run on every
 * page for every user. spatie's hasPermissionTo() throws when a permission is
 * not registered — which would turn an unseeded database into a 500 on every
 * screen instead of a hidden nav item. Deny instead.
 */
it('denies rather than blowing up when a permission is not registered', function () {
    Permission::query()->where('name', 'terminate leases without approval')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $supervisor = approvalUser('supervisor');

    expect($supervisor->mayActWithoutApproval(App\Enums\Permission::TerminateLeases))->toBeFalse()
        ->and(approvals()->decidableActions($supervisor))->not->toContain(ApprovalAction::TerminateLease);

    // And the layout still renders for a user with no roles at all.
    actingAs(User::factory()->create())->get('/settings/profile')->assertOk();
});
