<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\ReminderKind;
use App\Livewire\Portal\Home as PortalHome;
use App\Livewire\Portal\Login as PortalLogin;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\NotificationLog;
use App\Models\Receipt;
use App\Models\Tenant;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Services\Sms\SmsSender;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ReminderRulesSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\Support\FakeSmsSender;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->seed(ReminderRulesSeeder::class);

    $this->sms = new FakeSmsSender;
    $this->app->instance(SmsSender::class, $this->sms);
});

/**
 * A tenant with a mobile, one active lease and `$invoices` MVR 500 invoices.
 */
function portalTenant(string $mobile = '7771234', int $invoices = 2, array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->create(['mobile' => $mobile, ...$attributes]);
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    foreach (range(0, $invoices - 1) as $i) {
        app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01')->addMonths($i));
    }

    return $tenant;
}

/**
 * Pull the 6-digit code out of the fake gateway's last message.
 */
function lastOtpCode(FakeSmsSender $sms): string
{
    preg_match('/\b(\d{6})\b/', $sms->sent[array_key_last($sms->sent)]['message'], $m);

    return $m[1];
}

/*
|--------------------------------------------------------------------------
| OTP sign-in
|--------------------------------------------------------------------------
*/

it('signs a tenant in with a texted one-time code — no password anywhere', function () {
    $tenant = portalTenant();

    Livewire::test(PortalLogin::class)
        ->set('mobile', '7771234')
        ->call('requestCode')
        ->assertSet('step', 'code');

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['message'])->toContain('sign-in code');

    Livewire::test(PortalLogin::class)
        ->set('mobile', '7771234')
        ->set('step', 'code')
        ->set('code', lastOtpCode($this->sms))
        ->call('verify')
        ->assertRedirect(route('portal.home'));

    expect(Auth::guard('tenant')->id())->toBe($tenant->id);
});

it('matches mobiles across the register’s mixed formats', function () {
    $tenant = portalTenant('+960 777-1234');   // stored with prefix and noise

    Livewire::test(PortalLogin::class)
        ->set('mobile', '7771234')             // entered bare
        ->call('requestCode');

    Livewire::test(PortalLogin::class)
        ->set('mobile', '7771234')
        ->set('step', 'code')
        ->set('code', lastOtpCode($this->sms))
        ->call('verify');

    expect(Auth::guard('tenant')->id())->toBe($tenant->id);
});

it('answers identically for unknown mobiles and sends nothing — no tenant enumeration', function () {
    portalTenant();

    Livewire::test(PortalLogin::class)
        ->set('mobile', '7999999')             // not registered
        ->call('requestCode')
        ->assertHasNoErrors()
        ->assertSet('step', 'code');           // the same next screen…

    expect($this->sms->sent)->toBeEmpty();     // …but no SMS left the building
});

it('masks the code in the notification log', function () {
    portalTenant();

    Livewire::test(PortalLogin::class)->set('mobile', '7771234')->call('requestCode');

    $log = NotificationLog::where('kind', ReminderKind::PortalOtp->value)->sole();
    $code = lastOtpCode($this->sms);

    expect($log->content)->not->toContain($code)
        ->and($log->content)->toContain('masked');
});

it('rejects a wrong code, and burns the code after 5 attempts', function () {
    portalTenant();

    Livewire::test(PortalLogin::class)->set('mobile', '7771234')->call('requestCode');
    $real = lastOtpCode($this->sms);

    $attempt = fn (string $code) => Livewire::test(PortalLogin::class)
        ->set('mobile', '7771234')->set('step', 'code')->set('code', $code)->call('verify');

    foreach (range(1, 5) as $i) {
        $attempt('000000')->assertHasErrors('code');
    }

    // Five misses consumed the allowance — even the REAL code is dead now.
    $attempt($real)->assertHasErrors('code');

    expect(Auth::guard('tenant')->check())->toBeFalse();
});

it('expires codes after five minutes', function () {
    portalTenant();

    Livewire::test(PortalLogin::class)->set('mobile', '7771234')->call('requestCode');
    $code = lastOtpCode($this->sms);

    $this->travel(6)->minutes();

    Livewire::test(PortalLogin::class)
        ->set('mobile', '7771234')->set('step', 'code')->set('code', $code)
        ->call('verify')
        ->assertHasErrors('code');
});

it('allows at most 3 codes per mobile per hour', function () {
    portalTenant();

    foreach (range(1, 3) as $i) {
        Livewire::test(PortalLogin::class)->set('mobile', '7771234')->call('requestCode')->assertHasNoErrors();
    }

    Livewire::test(PortalLogin::class)
        ->set('mobile', '7771234')
        ->call('requestCode')
        ->assertHasErrors('mobile');

    expect($this->sms->sent)->toHaveCount(3);
});

it('sends the OTP even to tenants who opted out of reminder SMS', function () {
    // Opt-out governs marketing-ish notices; a sign-in code was just asked for.
    portalTenant('7771234', 1, ['sms_opt_out' => true]);

    Livewire::test(PortalLogin::class)->set('mobile', '7771234')->call('requestCode');

    expect($this->sms->sent)->toHaveCount(1);
});

it('offers a chooser when one mobile holds several tenant records', function () {
    $a = portalTenant('7771234');
    $b = portalTenant('7771234');   // legacy-import reality: same mobile, two records

    Livewire::test(PortalLogin::class)->set('mobile', '7771234')->call('requestCode');

    $component = Livewire::test(PortalLogin::class)
        ->set('mobile', '7771234')->set('step', 'code')
        ->set('code', lastOtpCode($this->sms))
        ->call('verify')
        ->assertSet('step', 'choose')
        ->assertSee($a->name)
        ->assertSee($b->name);

    $component->call('choose', $b->id)->assertRedirect(route('portal.home'));

    expect(Auth::guard('tenant')->id())->toBe($b->id);
});

it('refuses to open a tenant the verified mobile does not own', function () {
    portalTenant('7771234');
    portalTenant('7771234');
    $stranger = portalTenant('7999999');

    Livewire::test(PortalLogin::class)->set('mobile', '7771234')->call('requestCode');

    Livewire::test(PortalLogin::class)
        ->set('mobile', '7771234')->set('step', 'code')
        ->set('code', lastOtpCode($this->sms))
        ->call('verify')
        ->call('choose', $stranger->id)
        ->assertStatus(403);

    expect(Auth::guard('tenant')->check())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The portal itself: scoping and isolation
|--------------------------------------------------------------------------
*/

it('shows the tenant their own leases, balance, invoices and ledger', function () {
    $tenant = portalTenant('7771234', 2);
    app(PaymentRecorder::class)->record(
        Invoice::whereHas('lease', fn ($q) => $q->where('tenant_id', $tenant->id))->orderBy('id')->first(),
        Money::fromLaari(50_000),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    );

    Auth::guard('tenant')->login($tenant);

    Livewire::test(PortalHome::class)
        ->assertSee($tenant->name)
        ->assertSee('MVR 500.00')                       // remaining balance
        ->call('setTab', 'invoices')
        ->assertSee('2026/001')
        ->call('setTab', 'receipts')
        ->assertSee('Receipt 2026/001')
        ->call('setTab', 'statement')
        ->assertSee('Closing balance');
});

it('never leaks another tenant’s records — pages or PDFs', function () {
    $mine = portalTenant('7771234');
    $theirs = portalTenant('7999999');

    $theirInvoice = Invoice::whereHas('lease', fn ($q) => $q->where('tenant_id', $theirs->id))->firstOrFail();
    app(PaymentRecorder::class)->record($theirInvoice, Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash);
    $theirReceipt = $theirs->fresh()->load('leases')->id;

    Auth::guard('tenant')->login($mine);

    // Their names and numbers never render on my pages…
    Livewire::test(PortalHome::class)
        ->assertDontSee($theirs->name)
        ->call('setTab', 'invoices')
        ->assertDontSee($theirInvoice->number);

    // …and their documents 404 rather than acknowledging they exist.
    get(route('portal.invoices.pdf', $theirInvoice))->assertNotFound();
    get(route('portal.receipts.pdf', Receipt::where('tenant_id', $theirs->id)->firstOrFail()))->assertNotFound();
});

it('serves the tenant their own PDFs', function () {
    $tenant = portalTenant('7771234');
    $invoice = Invoice::whereHas('lease', fn ($q) => $q->where('tenant_id', $tenant->id))->firstOrFail();
    app(PaymentRecorder::class)->record($invoice, Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash);

    Auth::guard('tenant')->login($tenant);

    Pdf::fake();

    get(route('portal.invoices.pdf', $invoice))->assertOk();
    get(route('portal.receipts.pdf', Receipt::where('tenant_id', $tenant->id)->firstOrFail()))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Guard boundaries
|--------------------------------------------------------------------------
*/

it('sends signed-out visitors to the portal login, not the staff one', function () {
    get('/portal')->assertRedirect(route('portal.login'));
});

it('keeps a signed-in tenant out of every staff surface', function () {
    $tenant = portalTenant();
    Auth::guard('tenant')->login($tenant);

    // The tenant guard carries no weight on staff routes: still a guest there.
    get('/dashboard')->assertRedirect(route('login'));
    get('/invoices')->assertRedirect(route('login'));
    get('/leases')->assertRedirect(route('login'));
});

it('signs out cleanly', function () {
    $tenant = portalTenant();
    Auth::guard('tenant')->login($tenant);

    Livewire::test(PortalHome::class)->call('logout')->assertRedirect(route('portal.login'));

    expect(Auth::guard('tenant')->check())->toBeFalse();
});
