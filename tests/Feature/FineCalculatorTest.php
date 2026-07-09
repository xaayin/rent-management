<?php

declare(strict_types=1);

use App\Models\FineRule;
use App\Services\Billing\FineCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;

function fineCalculator(): FineCalculator
{
    return new FineCalculator;
}

$due = CarbonImmutable::parse('2026-01-10');

/*
 | The worked-example table from docs/BUILD_PLAN.md Slice 4 (PRD §4.7, §5.3).
 */

it('matches the PRD percent-per-day worked example (ABID ledger)', function () use ($due) {
    // Rent MVR 500, 0.5%/day of rent, paid 422 days late.
    $rule = FineRule::factory()->percentPerDay(50)->create();
    $rent = Money::fromRufiyaa(500);

    $breakdown = fineCalculator()->calculate($rule, $rent, $due, $due->addDays(422));

    expect($breakdown->lateDays)->toBe(422)
        ->and($breakdown->dailyLaari)->toBe(250)                       // 0.5% × 500 = MVR 2.50/day
        ->and($breakdown->totalLaari)->toBe(105_500)
        ->and($breakdown->total()->format())->toBe('MVR 1,055.00')
        ->and($rent->add($breakdown->total())->format())->toBe('MVR 1,555.00');
});

it('matches the PRD flat-per-day worked example', function () use ($due) {
    // Rent MVR 500, flat MVR 3.75/day, paid 422 days late.
    $rule = FineRule::factory()->flatPerDay(375)->create();

    $breakdown = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, $due->addDays(422));

    expect($breakdown->totalLaari)->toBe(158_250)
        ->and($breakdown->total()->format())->toBe('MVR 1,582.50');
});

it('computes the tiered fixed-monthly fine per overdue month', function (string $asOf, int $months, int $expectedLaari) use ($due) {
    // First month MVR 100, each subsequent month MVR 50 (locked defaults).
    $rule = FineRule::factory()->tiered(10_000, 5_000)->create();

    $breakdown = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, CarbonImmutable::parse($asOf));

    expect($breakdown->overdueMonths)->toBe($months)
        ->and($breakdown->totalLaari)->toBe($expectedLaari);
})->with([
    '1 month late — MVR 100' => ['2026-02-05', 1, 10_000],
    '2 months late — MVR 150' => ['2026-03-05', 2, 15_000],
    '3 months late — MVR 200' => ['2026-04-05', 3, 20_000],
]);

it('charges no fine when paid on or before the due date', function (string $method) use ($due) {
    $rule = match ($method) {
        'flat' => FineRule::factory()->flatPerDay(375)->create(),
        'percent' => FineRule::factory()->percentPerDay(50)->create(),
        'tiered' => FineRule::factory()->tiered(10_000, 5_000)->create(),
    };

    $onDue = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, $due);
    $early = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, $due->subDays(5));

    expect($onDue->totalLaari)->toBe(0)
        ->and($onDue->lateDays)->toBe(0)
        ->and($early->totalLaari)->toBe(0);
})->with(['flat', 'percent', 'tiered']);

/*
 | Business-rule edge cases (§5.3, FR-FIN-03/10).
 */

it('counts a partial overdue month as a full month, and exact months exactly', function () use ($due) {
    $rule = FineRule::factory()->tiered(10_000, 5_000)->create();

    // Exactly one month late (Jan 10 → Feb 10) is still the first month.
    $exact = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, $due->addMonths(1));
    // One month + one day commences the second month.
    $partial = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, $due->addMonths(1)->addDay());

    expect($exact->overdueMonths)->toBe(1)
        ->and($exact->totalLaari)->toBe(10_000)
        ->and($partial->overdueMonths)->toBe(2)
        ->and($partial->totalLaari)->toBe(15_000);
});

it('ignores the time of day at an exact-month boundary', function () use ($due) {
    $rule = FineRule::factory()->tiered(10_000, 5_000)->create();

    // Exactly one month late, but computed mid-afternoon — still month 1.
    $breakdown = fineCalculator()->calculate(
        $rule,
        Money::fromRufiyaa(500),
        $due,
        CarbonImmutable::parse('2026-02-10 15:30:00'),
    );

    expect($breakdown->overdueMonths)->toBe(1)
        ->and($breakdown->totalLaari)->toBe(10_000);
});

it('applies allowance days before fines start (FR-FIN-03)', function () use ($due) {
    $rule = FineRule::factory()->percentPerDay(50)->allowanceDays(30)->create();

    $within = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, $due->addDays(30));
    $beyond = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, $due->addDays(31));

    expect($within->totalLaari)->toBe(0)
        ->and($beyond->lateDays)->toBe(1)
        ->and($beyond->totalLaari)->toBe(250);
});

it('applies the optional maximum cap (FR-FIN-03)', function () use ($due) {
    $rule = FineRule::factory()->flatPerDay(375)->cap(50_000)->create();

    $breakdown = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, $due->addDays(422));

    expect($breakdown->totalLaari)->toBe(50_000)
        ->and($breakdown->capped)->toBeTrue();
});

it('accrues against rent plus charges when so configured', function () use ($due) {
    $rule = FineRule::factory()->percentPerDay(50)->baseRentPlusCharges()->create();

    // Base MVR 600 (rent 500 + charges 100) → MVR 3.00/day.
    $breakdown = fineCalculator()->calculate($rule, Money::fromRufiyaa(600), $due, $due->addDays(10));

    expect($breakdown->dailyLaari)->toBe(300)
        ->and($breakdown->totalLaari)->toBe(3_000);
});

it('returns the full itemised breakdown for the invoice (FR-FIN-12)', function () use ($due) {
    $rule = FineRule::factory()->tiered(10_000, 5_000)->create();

    $breakdown = fineCalculator()->calculate($rule, Money::fromRufiyaa(500), $due, CarbonImmutable::parse('2026-04-05'));
    $meta = $breakdown->toArray();

    expect($meta['method'])->toBe('tiered_monthly')
        ->and($meta['due_date'])->toBe('2026-01-10')
        ->and($meta['as_of'])->toBe('2026-04-05')
        ->and($meta['overdue_months'])->toBe(3)
        ->and($meta['total_laari'])->toBe(20_000)
        ->and($breakdown->tierLines)->toHaveCount(2)
        ->and($breakdown->tierLines[0]['amount_laari'])->toBe(10_000)   // first month
        ->and($breakdown->tierLines[1]['amount_laari'])->toBe(10_000)   // 2 further months × 50
        ->and($breakdown->summary())->toContain('3 overdue months');
});
