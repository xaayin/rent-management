<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReminderKind;
use App\Jobs\SendPaymentReminders;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\NotificationLog;
use App\Models\ReminderRule;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Services\Reminders\TemplateRenderer;
use App\Services\Sms\SmsSender;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ReminderRulesSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeSmsSender;

beforeEach(function () {
    $this->seed(ReminderRulesSeeder::class);

    $this->sms = new FakeSmsSender;
    $this->app->instance(SmsSender::class, $this->sms);
});

/**
 * A flat MVR 500/month lease invoiced for January 2026, due 2026-01-10.
 */
function reminderInvoice(array $tenantAttributes = []): Invoice
{
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    if ($tenantAttributes !== []) {
        $lease->tenant->update($tenantAttributes);
    }

    return app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));
}

it('sends the pre-due reminder with merged fields and logs it (FR-NOT-02/03/05)', function () {
    $invoice = reminderInvoice(['mobile' => '+9607771234']);

    // Default pre-due rule: 3 days before the 2026-01-10 due date.
    SendPaymentReminders::dispatchSync('2026-01-07');

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['to'])->toBe('+9607771234')
        ->and($this->sms->sent[0]['message'])->toContain($invoice->lease->tenant->name)
        ->and($this->sms->sent[0]['message'])->toContain('MVR 500.00')
        ->and($this->sms->sent[0]['message'])->toContain('10 January 2026')
        ->and($this->sms->sent[0]['message'])->toContain($invoice->number)
        ->and($this->sms->sent[0]['message'])->toContain(config('billing.payment_account'));

    $log = NotificationLog::sole();

    expect($log->kind)->toBe(ReminderKind::PreDue)
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->invoice_id)->toBe($invoice->id)
        ->and($log->recipient)->toBe('+9607771234')
        ->and($log->content)->toBe($this->sms->sent[0]['message']);
});

it('sends on-due and overdue reminders on their configured days', function () {
    reminderInvoice();

    SendPaymentReminders::dispatchSync('2026-01-10'); // on due
    SendPaymentReminders::dispatchSync('2026-01-13'); // 3 days after

    expect($this->sms->sent)->toHaveCount(2)
        ->and(NotificationLog::where('kind', ReminderKind::OnDue->value)->count())->toBe(1)
        ->and(NotificationLog::where('kind', ReminderKind::Overdue->value)->count())->toBe(1);
});

it('never reminds on a paid invoice (FR-NOT-07)', function () {
    $invoice = reminderInvoice();

    app(PaymentRecorder::class)->record(
        $invoice,
        Money::fromLaari(50_000),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    );

    // Paying sends its own confirmation (T1) — that is the only SMS allowed.
    $sentAfterPayment = count($this->sms->sent);

    SendPaymentReminders::dispatchSync('2026-01-07');  // pre-due
    SendPaymentReminders::dispatchSync('2026-01-13');  // overdue

    expect($this->sms->sent)->toHaveCount($sentAfterPayment)
        ->and(NotificationLog::whereIn('kind', [
            ReminderKind::PreDue->value,
            ReminderKind::OnDue->value,
            ReminderKind::Overdue->value,
        ])->count())->toBe(0);
});

it('does not send duplicates within a cycle (FR-NOT-09)', function () {
    reminderInvoice();

    SendPaymentReminders::dispatchSync('2026-01-07');
    SendPaymentReminders::dispatchSync('2026-01-07');

    expect($this->sms->sent)->toHaveCount(1)
        ->and(NotificationLog::count())->toBe(1);
});

it('sends nothing when the rule is disabled (FR-NOT-02 toggles)', function () {
    reminderInvoice();
    ReminderRule::where('kind', ReminderKind::PreDue->value)->update(['enabled' => false]);

    SendPaymentReminders::dispatchSync('2026-01-07');

    expect($this->sms->sent)->toBeEmpty();
});

it('skips tenants who opted out of SMS (§5.5.26)', function () {
    reminderInvoice(['sms_opt_out' => true]);

    SendPaymentReminders::dispatchSync('2026-01-07');

    expect($this->sms->sent)->toBeEmpty()
        ->and(NotificationLog::count())->toBe(0);
});

it('logs failed sends and retries them on the next run (FR-NOT-05/06)', function () {
    reminderInvoice();

    $this->sms->shouldFail = true;
    SendPaymentReminders::dispatchSync('2026-01-07');

    $failed = NotificationLog::sole();

    expect($failed->status)->toBe(NotificationStatus::Failed)
        ->and($failed->error)->toBe('gateway unreachable');

    // Gateway recovers — the same reminder goes out on the next run.
    $this->sms->shouldFail = false;
    SendPaymentReminders::dispatchSync('2026-01-07');

    expect(NotificationLog::where('status', NotificationStatus::Sent->value)->count())->toBe(1)
        ->and($this->sms->sent)->toHaveCount(2);
});

it('renders edited templates (FR-NOT-03 editable)', function () {
    reminderInvoice();

    ReminderRule::where('kind', ReminderKind::PreDue->value)
        ->update(['template' => 'REMINDER {invoice_number}: {amount_due} due {due_date}.']);

    SendPaymentReminders::dispatchSync('2026-01-07');

    expect($this->sms->sent[0]['message'])
        ->toStartWith('REMINDER 2026/')
        ->toContain('MVR 500.00 due 10 January 2026.');
});

it('replaces every documented merge field', function () {
    $invoice = reminderInvoice();

    $rendered = app(TemplateRenderer::class)->render(
        implode(' ', TemplateRenderer::FIELDS),
        $invoice,
    );

    expect($rendered)->not->toContain('{')
        ->and($rendered)->toContain($invoice->lease->property->name)
        ->and($rendered)->toContain('January 2026');
});

it('quotes the current fine in overdue reminders', function () {
    // 0.5%/day rule: by Jan 13 the fine is 3 × MVR 2.50 = MVR 7.50.
    $invoice = reminderInvoice();
    FineRule::factory()->percentPerDay(50)->create([
        'lease_id' => $invoice->lease_id,
        'effective_from' => '2020-01-01',
    ]);
    Artisan::call('invoices:refresh-fines', ['--as-of' => '2026-01-13']);

    SendPaymentReminders::dispatchSync('2026-01-13');

    expect($this->sms->sent[0]['message'])
        ->toContain('MVR 507.50')   // amount due incl. fine
        ->toContain('MVR 7.50');    // the fine itself
});
