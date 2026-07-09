<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Enums\ReminderKind;
use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Livewire\Settings\Reminders as ReminderSettings;
use App\Models\Lease;
use App\Models\NotificationLog;
use App\Models\ReminderRule;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Sms\SmsSender;
use Carbon\CarbonImmutable;
use Database\Seeders\ReminderRulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Tests\Support\FakeSmsSender;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ReminderRulesSeeder::class);

    $this->sms = new FakeSmsSender;
    $this->app->instance(SmsSender::class, $this->sms);
});

function reminderUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('lets an administrator edit schedules and templates but blocks other roles (§6.1)', function () {
    actingAs(reminderUser('finance_officer'))->get('/settings/reminders')->assertForbidden();
    actingAs(reminderUser('supervisor'))->get('/settings/reminders')->assertForbidden();
    actingAs(reminderUser('administrator'))->get('/settings/reminders')->assertOk();

    $rule = ReminderRule::where('kind', ReminderKind::PreDue->value)->first();

    Livewire::test(ReminderSettings::class)
        ->set("rules.{$rule->id}.days", 7)
        ->set("rules.{$rule->id}.enabled", false)
        ->set("rules.{$rule->id}.template", 'Rent {amount_due} due {due_date} — invoice {invoice_number}.')
        ->call('save')
        ->assertHasNoErrors();

    $rule->refresh();

    expect($rule->days)->toBe(7)
        ->and($rule->enabled)->toBeFalse()
        ->and($rule->template)->toContain('Rent {amount_due}');
});

it('rejects an empty template', function () {
    actingAs(reminderUser('administrator'));

    $rule = ReminderRule::where('kind', ReminderKind::OnDue->value)->first();

    Livewire::test(ReminderSettings::class)
        ->set("rules.{$rule->id}.template", '')
        ->call('save')
        ->assertHasErrors(["rules.{$rule->id}.template"]);
});

it('sends a manual reminder from the invoices screen (FR-NOT-08)', function () {
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    actingAs(reminderUser('finance_officer'));

    Livewire::test(InvoicesIndex::class)
        ->call('sendReminder', $invoice->id)
        ->assertHasNoErrors();

    $log = NotificationLog::sole();

    expect($this->sms->sent)->toHaveCount(1)
        ->and($log->kind)->toBe(ReminderKind::Manual)
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->invoice_id)->toBe($invoice->id);

    // Manual sends are not deduplicated — staff may resend at will.
    Livewire::test(InvoicesIndex::class)->call('sendReminder', $invoice->id);
    expect(NotificationLog::count())->toBe(2);
});

it('reports when a manual reminder is skipped for an opted-out tenant', function () {
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
    ]);
    $lease->tenant->update(['sms_opt_out' => true]);
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    actingAs(reminderUser('finance_officer'));

    Livewire::test(InvoicesIndex::class)->call('sendReminder', $invoice->id);

    expect($this->sms->sent)->toBeEmpty()
        ->and(NotificationLog::count())->toBe(0);
});
