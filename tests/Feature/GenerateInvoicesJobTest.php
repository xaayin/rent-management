<?php

declare(strict_types=1);

use App\Jobs\GenerateInvoices;
use App\Models\Invoice;
use App\Models\Lease;

use function Pest\Laravel\artisan;

$billable = [
    'start_date' => '2026-01-01',
    'rent_start_date' => '2026-01-01',
    'expiry_date' => '2036-01-01',
];

it('generates one invoice per active lease and skips inactive ones', function () use ($billable) {
    Lease::factory()->count(3)->active()->create($billable);
    Lease::factory()->create(array_merge($billable, ['status' => 'draft']));

    GenerateInvoices::dispatchSync('2026-03');

    expect(Invoice::count())->toBe(3);
});

it('does not duplicate invoices when re-run for the same period (FR-INV-06)', function () use ($billable) {
    Lease::factory()->count(2)->active()->create($billable);

    GenerateInvoices::dispatchSync('2026-03');
    GenerateInvoices::dispatchSync('2026-03');

    expect(Invoice::count())->toBe(2);
});

it('can be triggered manually via the artisan command (FR-INV-05)', function () use ($billable) {
    Lease::factory()->active()->create($billable);

    artisan('invoices:generate', ['--period' => '2026-03'])->assertSuccessful();

    expect(Invoice::count())->toBe(1);
});
