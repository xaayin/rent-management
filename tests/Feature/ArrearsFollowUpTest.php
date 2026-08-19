<?php

declare(strict_types=1);

use App\Enums\FollowUpState;
use App\Enums\PaymentMethod;
use App\Enums\PromiseOutcome;
use App\Livewire\FollowUps\Index as FollowUpsIndex;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Services\Collections\ArrearsFollowUpService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The arrears follow-up queue turns "who owes" into "who to chase today".
 *
 * A promise to pay snoozes a tenant out of the queue until the promised date,
 * then resurfaces them at the TOP as a broken promise if the money never
 * arrived. Whether a promise was kept is judged on payments alone — never on
 * anyone remembering to tick it off.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** A tenant owing one overdue invoice of MVR 500, due 10 Jan 2026. */
function arrearsTenant(): Tenant
{
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    return $lease->tenant;
}

function followUps(): ArrearsFollowUpService
{
    return app(ArrearsFollowUpService::class);
}

function payTenant(Tenant $tenant, int $laari, string $date): void
{
    app(PaymentRecorder::class)->recordForTenant(
        $tenant, Money::fromLaari($laari), CarbonImmutable::parse($date), PaymentMethod::Cash,
    );
}

/** The queue row for a tenant, or null when they are not in it. */
function queueRow(Tenant $tenant, string $on = '2026-02-01', string $filter = 'all'): ?array
{
    return followUps()->queue(CarbonImmutable::parse($on), $filter)
        ->firstWhere('tenant.id', $tenant->id);
}

// --- Who is in the queue ------------------------------------------------------

it('lists an overdue tenant nobody has contacted yet', function () {
    $tenant = arrearsTenant();

    $row = queueRow($tenant);

    expect($row)->not->toBeNull()
        ->and($row['state'])->toBe(FollowUpState::NeverContacted)
        ->and($row['outstanding']->laari)->toBe(50_000)
        ->and($row['days_overdue'])->toBe(22)
        ->and($row['last_contact'])->toBeNull();
});

it('leaves out tenants who owe nothing', function () {
    $tenant = arrearsTenant();
    payTenant($tenant, 50_000, '2026-01-20');

    expect(queueRow($tenant))->toBeNull();
});

// --- Promises -----------------------------------------------------------------

it('snoozes a tenant who promises to pay by a future date', function () {
    $tenant = arrearsTenant();

    followUps()->log($tenant, [
        'contacted_on' => '2026-02-01',
        'channel' => 'call',
        'note' => 'Spoke to the tenant; away on the atoll until the 20th.',
        'promised_on' => '2026-02-25',
    ], null);

    $row = queueRow($tenant, '2026-02-05');

    expect($row['state'])->toBe(FollowUpState::Promised)
        ->and($row['promise_outcome'])->toBe(PromiseOutcome::Open)
        // and they are out of the day's worklist
        ->and(followUps()->queue(CarbonImmutable::parse('2026-02-05'), 'attention')
            ->firstWhere('tenant.id', $tenant->id))->toBeNull();
});

it('resurfaces a broken promise at the top of the queue', function () {
    $tenant = arrearsTenant();

    followUps()->log($tenant, [
        'contacted_on' => '2026-02-01',
        'channel' => 'call',
        'note' => 'Promised to settle in full.',
        'promised_on' => '2026-02-25',
    ], null);

    // The 25th came and went with no money.
    $row = queueRow($tenant, '2026-02-26');

    expect($row['state'])->toBe(FollowUpState::Broken)
        ->and($row['promise_outcome'])->toBe(PromiseOutcome::Broken)
        ->and(followUps()->queue(CarbonImmutable::parse('2026-02-26'), 'attention')->first()['tenant']->id)
        ->toBe($tenant->id);
});

it('treats a promise as kept once the payments cover it', function () {
    $tenant = arrearsTenant();

    followUps()->log($tenant, [
        'contacted_on' => '2026-02-01',
        'channel' => 'call',
        'note' => 'Promised MVR 200 by the 20th.',
        'promised_on' => '2026-02-20',
        'promised_amount' => '200.00',
    ], null);

    payTenant($tenant, 20_000, '2026-02-18');

    $contact = $tenant->arrearsContacts()->latest('id')->first();

    expect(followUps()->outcomeFor($contact, CarbonImmutable::parse('2026-02-26')))
        ->toBe(PromiseOutcome::Kept);
});

it('breaks a promise the tenant only part-paid', function () {
    $tenant = arrearsTenant();

    followUps()->log($tenant, [
        'contacted_on' => '2026-02-01',
        'channel' => 'call',
        'note' => 'Promised MVR 200 by the 20th.',
        'promised_on' => '2026-02-20',
        'promised_amount' => '200.00',
    ], null);

    payTenant($tenant, 5_000, '2026-02-18');   // only MVR 50 of the MVR 200

    $contact = $tenant->arrearsContacts()->latest('id')->first();

    expect(followUps()->outcomeFor($contact, CarbonImmutable::parse('2026-02-26')))
        ->toBe(PromiseOutcome::Broken);
});

it('un-keeps a promise whose payment was reversed', function () {
    $tenant = arrearsTenant();

    followUps()->log($tenant, [
        'contacted_on' => '2026-02-01', 'channel' => 'call',
        'note' => 'Promised MVR 200.', 'promised_on' => '2026-02-20', 'promised_amount' => '200.00',
    ], null);

    payTenant($tenant, 20_000, '2026-02-18');
    $payment = $tenant->leases->first()->invoices->first()->payments()->where('type', 'payment')->first();
    app(PaymentRecorder::class)->reverse($payment, 'Cheque bounced.', CarbonImmutable::parse('2026-02-19'));

    $contact = $tenant->arrearsContacts()->latest('id')->first();

    // The reversal is a negative row, so the net since contact is zero again.
    expect(followUps()->outcomeFor($contact, CarbonImmutable::parse('2026-02-26')))
        ->toBe(PromiseOutcome::Broken);
});

it('does not snooze a plain note with no promised date', function () {
    $tenant = arrearsTenant();

    followUps()->log($tenant, [
        'contacted_on' => '2026-02-01',
        'channel' => 'visit',
        'note' => 'Premises locked, left a notice.',
    ], null);

    $row = queueRow($tenant, '2026-02-02');

    expect($row['promise_outcome'])->toBe(PromiseOutcome::None)
        ->and($row['state'])->toBe(FollowUpState::Recent)
        ->and($row['last_contact']->note)->toBe('Premises locked, left a notice.');
});

it('goes stale when a contact without a promise gets old', function () {
    $tenant = arrearsTenant();

    followUps()->log($tenant, [
        'contacted_on' => '2026-02-01', 'channel' => 'call', 'note' => 'No answer.',
    ], null);

    expect(queueRow($tenant, '2026-02-10')['state'])->toBe(FollowUpState::Recent)
        ->and(queueRow($tenant, '2026-03-01')['state'])->toBe(FollowUpState::Stale);
});

// --- Ordering and the council's number ----------------------------------------

it('puts broken promises above tenants nobody has called', function () {
    $broken = arrearsTenant();
    $untouched = arrearsTenant();

    followUps()->log($broken, [
        'contacted_on' => '2026-02-01', 'channel' => 'call',
        'note' => 'Promised.', 'promised_on' => '2026-02-10',
    ], null);

    $order = followUps()->queue(CarbonImmutable::parse('2026-02-26'), 'attention')
        ->pluck('tenant.id')->all();

    expect($order[0])->toBe($broken->id)
        ->and($order)->toContain($untouched->id);
});

it('splits arrears into money promised and money nobody has secured', function () {
    $promised = arrearsTenant();
    arrearsTenant();   // never contacted

    followUps()->log($promised, [
        'contacted_on' => '2026-02-01', 'channel' => 'call',
        'note' => 'Will pay.', 'promised_on' => '2026-02-25',
    ], null);

    $summary = followUps()->summary(CarbonImmutable::parse('2026-02-05'));

    expect($summary['promised']->laari)->toBe(50_000)
        ->and($summary['unsecured']->laari)->toBe(50_000)
        ->and($summary['needs_attention'])->toBe(1);
});

// --- Screen -------------------------------------------------------------------

it('logs a follow-up from the queue screen', function () {
    $tenant = arrearsTenant();

    $finance = User::factory()->create();
    $finance->assignRole('finance_officer');
    actingAs($finance);

    Livewire::test(FollowUpsIndex::class)
        ->call('startContact', $tenant->id)
        ->call('saveContact')
        ->assertHasErrors('contact_note')            // the note is the whole point
        ->set('contact_note', 'Called — promises to pay on the 25th.')
        ->set('contact_promised_on', '2026-12-25')
        ->call('saveContact')
        ->assertHasNoErrors();

    $contact = $tenant->arrearsContacts()->latest('id')->first();

    expect($contact->note)->toBe('Called — promises to pay on the 25th.')
        ->and($contact->promised_on->toDateString())->toBe('2026-12-25')
        ->and($contact->recorded_by)->toBe($finance->id)
        ->and($contact->outstanding_at_contact_laari)->toBe(50_000);
});

it('keeps the queue away from roles that do not collect money', function () {
    $land = User::factory()->create();
    $land->assignRole('land_officer');

    actingAs($land)->get('/follow-ups')->assertForbidden();

    $finance = User::factory()->create();
    $finance->assignRole('finance_officer');
    actingAs($finance)->get('/follow-ups')->assertOk();
});
