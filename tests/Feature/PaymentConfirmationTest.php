<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReminderKind;
use App\Jobs\SendPaymentReminders;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\NotificationLog;
use App\Models\ReminderRule;
use App\Models\Tenant;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Services\Reminders\TemplateRenderer;
use App\Services\Sms\SmsSender;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ReminderRulesSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeSmsSender;

beforeEach(function () {
    $this->seed(ReminderRulesSeeder::class);

    $this->sms = new FakeSmsSender;
    $this->app->instance(SmsSender::class, $this->sms);
});

/**
 * A tenant with `$count` flat MVR 500/month invoices (Jan 2026 onward, due on
 * the 10th).
 *
 * @return array{0: Tenant, 1: Collection<int, Invoice>}
 */
function confirmable(int $count = 1, array $tenantAttributes = []): array
{
    $tenant = Tenant::factory()->create(['mobile' => '+9607771234', ...$tenantAttributes]);
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
| Payment confirmation SMS (T1)
|--------------------------------------------------------------------------
*/

it('texts the tenant a confirmation when their payment is recorded', function () {
    [, $invoices] = confirmable();

    $payment = app(PaymentRecorder::class)->record(
        $invoices[0], Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['to'])->toBe('+9607771234')
        ->and($this->sms->sent[0]['message'])->toContain($payment->receipt_number)
        ->and($this->sms->sent[0]['message'])->toContain('MVR 500.00');

    $log = NotificationLog::sole();

    expect($log->kind)->toBe(ReminderKind::PaymentConfirmation)
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->invoice_id)->toBeNull();   // the confirmation belongs to the receipt
});

it('sends ONE confirmation for a bulk receipt, not one per invoice', function () {
    [$tenant] = confirmable(3);

    $receipt = app(PaymentRecorder::class)->recordForTenant(
        $tenant, Money::fromRufiyaa('1500.00'), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['message'])->toContain($receipt->number)
        ->and($this->sms->sent[0]['message'])->toContain('MVR 1,500.00')
        ->and($this->sms->sent[0]['message'])->toContain('3 invoice')
        ->and(NotificationLog::where('kind', ReminderKind::PaymentConfirmation->value)->count())->toBe(1);
});

it('quotes the remaining balance so a partial payer knows where they stand', function () {
    [$tenant, $invoices] = confirmable(2);

    // MVR 500 of the MVR 1,000 owed — the message must say MVR 500.00 remains.
    app(PaymentRecorder::class)->recordForTenant(
        $tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    expect($this->sms->sent[0]['message'])->toContain('MVR 500.00');

    // And once everything is settled, the balance reads zero.
    app(PaymentRecorder::class)->record(
        $invoices[1]->fresh(), Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-06'), PaymentMethod::Cash,
    );

    expect($this->sms->sent[1]['message'])->toContain('MVR 0.00');
});

it('never texts about a reversal', function () {
    [, $invoices] = confirmable();

    $payment = app(PaymentRecorder::class)->record(
        $invoices[0], Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    $sentBefore = count($this->sms->sent);

    app(PaymentRecorder::class)->reverse($payment, 'Wrong tenant.', CarbonImmutable::parse('2026-01-06'));

    expect($this->sms->sent)->toHaveCount($sentBefore);
});

it('respects the tenant’s SMS opt-out — the payment still records', function () {
    [, $invoices] = confirmable(1, ['sms_opt_out' => true]);

    $payment = app(PaymentRecorder::class)->record(
        $invoices[0], Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    expect($payment->exists)->toBeTrue()
        ->and($this->sms->sent)->toBeEmpty()
        ->and(NotificationLog::count())->toBe(0);
});

it('sends nothing when the confirmation rule is disabled', function () {
    [, $invoices] = confirmable();
    ReminderRule::where('kind', ReminderKind::PaymentConfirmation->value)->update(['enabled' => false]);

    app(PaymentRecorder::class)->record(
        $invoices[0], Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    expect($this->sms->sent)->toBeEmpty();
});

it('records the payment even when the SMS gateway is down', function () {
    [, $invoices] = confirmable();
    $this->sms->shouldFail = true;

    $payment = app(PaymentRecorder::class)->record(
        $invoices[0], Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    // Money first: the receipt exists, and the failure is on the log.
    expect($payment->receipt_number)->toBe('2026/001')
        ->and(NotificationLog::sole()->status)->toBe(NotificationStatus::Failed);
});

it('replaces every documented receipt merge field', function () {
    [$tenant] = confirmable(2);

    $receipt = app(PaymentRecorder::class)->recordForTenant(
        $tenant, Money::fromRufiyaa('1000.00'), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    $rendered = app(TemplateRenderer::class)->renderReceipt(
        implode(' ', TemplateRenderer::RECEIPT_FIELDS),
        $receipt,
    );

    expect($rendered)->not->toContain('{')
        ->and($rendered)->toContain($receipt->number)
        ->and($rendered)->toContain($tenant->name);
});

/*
|--------------------------------------------------------------------------
| Monthly balance statement SMS (T1)
|--------------------------------------------------------------------------
*/

it('texts tenants in arrears their balance, once per month', function () {
    [$tenant] = confirmable(2);   // owes MVR 1,000

    $this->travelTo('2026-02-01');
    Artisan::call('tenants:send-balance-statements');

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['message'])->toContain('MVR 1,000.00')
        ->and($this->sms->sent[0]['message'])->toContain($tenant->name);

    // Re-running in the same month must not nag twice.
    $this->travelTo('2026-02-15');
    Artisan::call('tenants:send-balance-statements');

    expect($this->sms->sent)->toHaveCount(1);

    // A new month is a new statement.
    $this->travelTo('2026-03-01');
    Artisan::call('tenants:send-balance-statements');

    expect($this->sms->sent)->toHaveCount(2);
});

it('does not text settled or opted-out tenants their balance', function () {
    [$tenantPaid, $invoices] = confirmable();
    app(PaymentRecorder::class)->record(
        $invoices[0], Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );
    $this->sms->sent = [];   // discard the confirmation

    confirmable(1, ['sms_opt_out' => true, 'mobile' => '+9607775555']);

    Artisan::call('tenants:send-balance-statements');

    expect($this->sms->sent)->toBeEmpty();
});

it('sends no statements when the rule is disabled', function () {
    confirmable(2);
    ReminderRule::where('kind', ReminderKind::BalanceStatement->value)->update(['enabled' => false]);

    Artisan::call('tenants:send-balance-statements');

    expect($this->sms->sent)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| The new kinds must not break the existing reminder run
|--------------------------------------------------------------------------
*/

it('leaves the daily reminder run untouched by the new rule kinds', function () {
    confirmable();   // an unpaid invoice due 2026-01-10

    // The dispatcher iterates every enabled rule; the two new kinds are not
    // date-driven and must be skipped, not crash the nightly run.
    SendPaymentReminders::dispatchSync('2026-01-07');

    expect($this->sms->sent)->toHaveCount(1)
        ->and(NotificationLog::sole()->kind)->toBe(ReminderKind::PreDue);
});
