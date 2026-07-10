<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Models\FineRule;
use App\Models\Lease;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Services\Reporting\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\LaravelPdf\Facades\Pdf;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function reportUser(string $role = 'auditor'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * A deterministic January-2026 scenario:
 *  - lease A (flat MVR 500, 0.5%/day fine): invoiced, unpaid → overdue.
 *  - lease B (flat MVR 1,000): invoiced, fully paid on 2026-01-05.
 *  - one draft lease that must not count as active.
 * Fines refreshed as of 2026-01-20 → lease A fine = 10 × 2.50 = MVR 25.
 */
function reportScenario(): array
{
    $leaseA = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01', 'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01', 'due_day' => 10,
    ]);
    FineRule::factory()->percentPerDay(50)->create(['lease_id' => $leaseA->id, 'effective_from' => '2020-01-01']);

    $leaseB = Lease::factory()->active()->flat(100_000)->create([
        'start_date' => '2026-01-01', 'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01', 'due_day' => 10,
    ]);

    Lease::factory()->create(['status' => 'draft']);

    $generator = app(InvoiceGenerator::class);
    $invoiceA = $generator->generate($leaseA, CarbonImmutable::parse('2026-01-01'));
    $invoiceB = $generator->generate($leaseB, CarbonImmutable::parse('2026-01-01'));

    app(PaymentRecorder::class)->record(
        $invoiceB, Money::fromLaari(100_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-20']);

    return [$leaseA, $invoiceA->fresh(), $invoiceB->fresh()];
}

it('computes the dashboard metrics (FR-RPT-01)', function () {
    reportScenario();

    $metrics = app(ReportService::class)->dashboard(CarbonImmutable::parse('2026-01-20'));

    expect($metrics['active_leases'])->toBe(2)
        ->and($metrics['billed_laari'])->toBe(150_000)          // 500 + 1,000 principal
        ->and($metrics['billed_invoices'])->toBe(2)
        ->and($metrics['collected_laari'])->toBe(100_000)       // lease B payment
        ->and($metrics['arrears_laari'])->toBe(52_500)          // lease A rent + 25 fine
        ->and($metrics['overdue_invoices'])->toBe(1)
        ->and($metrics['fines_outstanding_laari'])->toBe(2_500);
});

it('lists arrears with days overdue and the current fine (FR-RPT-02)', function () {
    [, $invoiceA] = reportScenario();

    $rows = app(ReportService::class)->arrears(CarbonImmutable::parse('2026-01-20'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['invoice']->id)->toBe($invoiceA->id)
        ->and($rows[0]['days_overdue'])->toBe(10)
        ->and($rows[0]['outstanding_fine_laari'])->toBe(2_500)
        ->and($rows[0]['outstanding_laari'])->toBe(52_500);
});

it('lists upcoming expiries and grace endings within the window (FR-RPT-03)', function () {
    $today = CarbonImmutable::parse('2026-01-20');

    $expiring = Lease::factory()->active()->create(['expiry_date' => '2026-03-01']);   // 40 days out
    Lease::factory()->active()->create(['expiry_date' => '2027-06-01']);               // beyond window
    $grace = Lease::factory()->active()->graceMonths(3)->create([
        'rent_start_date' => '2025-12-01',                                             // grace ends 2026-03-01
        'expiry_date' => '2035-01-01',
    ]);

    $upcoming = app(ReportService::class)->upcoming($today);

    expect($upcoming)->toHaveCount(2)
        ->and($upcoming->pluck('lease.id'))->toContain($expiring->id, $grace->id);
});

it('reports income by month, property type and tenant type (FR-RPT-04)', function () {
    reportScenario();

    $service = app(ReportService::class);
    $months = $service->incomeByMonth(2026);

    expect($months->firstWhere('month', 1)['billed_laari'])->toBe(152_500)   // invoice totals incl. fine
        ->and($months->firstWhere('month', 1)['collected_laari'])->toBe(100_000)
        ->and($months->firstWhere('month', 6)['billed_laari'])->toBe(0);

    expect((int) $service->incomeByPropertyType(2026)->sum('laari'))->toBe(100_000)
        ->and((int) $service->incomeByTenantType(2026)->sum('laari'))->toBe(100_000);
});

it('renders the dashboard for every role', function (string $role) {
    reportScenario();

    actingAs(reportUser($role))
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Needs attention')
        ->assertSee('Upcoming expiries');
})->with(['administrator', 'finance_officer', 'land_officer', 'supervisor', 'auditor']);

it('renders the arrears and income report pages', function () {
    reportScenario();

    actingAs(reportUser('finance_officer'));

    $this->get('/reports/arrears')->assertOk()->assertSee('Arrears')->assertSee('MVR 525.00');
    $this->get('/reports/income?year=2026')->assertOk()->assertSee('Billed vs collected');
});

it('exports the arrears report as CSV (FR-RPT-05)', function () {
    [, $invoiceA] = reportScenario();

    $response = actingAs(reportUser())->get('/reports/arrears/export/csv');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Invoice,Tenant,Property')
        ->toContain($invoiceA->number)
        ->toContain('525.00');   // outstanding as plain decimal MVR
});

it('exports the income report as CSV', function () {
    reportScenario();

    $response = actingAs(reportUser())->get('/reports/income/export/csv?year=2026');

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain('Month,"Billed (MVR)","Collected (MVR)"')
        ->toContain('January,1525.00,1000.00');
});

it('exports both reports as PDF (FR-RPT-05)', function () {
    reportScenario();

    Pdf::fake();
    actingAs(reportUser());

    $this->get('/reports/arrears/export/pdf')->assertOk();
    Pdf::assertRespondedWithPdf(fn ($pdf) => $pdf->viewName === 'pdf.arrears-report');

    $this->get('/reports/income/export/pdf?year=2026')->assertOk();
    Pdf::assertRespondedWithPdf(fn ($pdf) => $pdf->viewName === 'pdf.income-report');
});

it('blocks report exports for guests', function () {
    $this->get('/reports/arrears/export/csv')->assertRedirect('/login');
});
