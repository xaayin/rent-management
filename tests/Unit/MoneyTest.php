<?php

declare(strict_types=1);

use App\Support\Money;

it('stores and returns integer laari', function () {
    expect(Money::fromLaari(106_000)->laari)->toBe(106_000);
});

it('builds from whole rufiyaa', function () {
    // 1060 rufiyaa = 106,000 laari.
    expect(Money::fromRufiyaa(1060)->laari)->toBe(106_000);
});

it('builds from a decimal rufiyaa string without floating point', function (string $input, int $laari) {
    expect(Money::fromRufiyaa($input)->laari)->toBe($laari);
})->with([
    ['10.60', 1_060],
    ['0.53', 53],        // 53 laari/ft² rate
    ['1060', 106_000],
    ['1060.00', 106_000],
    ['0', 0],
    ['0.05', 5],
]);

it('rounds sub-laari fractions half-up', function () {
    expect(Money::fromRufiyaa('10.999')->laari)->toBe(1_100)  // rounds up, carries into rufiyaa
        ->and(Money::fromRufiyaa('10.994')->laari)->toBe(1_099)
        ->and(Money::fromRufiyaa('10.995')->laari)->toBe(1_100);
});

it('supports negative amounts for reversing entries', function () {
    expect(Money::fromRufiyaa('-50.00')->laari)->toBe(-5_000)
        ->and(Money::fromLaari(-5_000)->isNegative())->toBeTrue();
});

it('rejects malformed rufiyaa input', function () {
    Money::fromRufiyaa('1,060.00');
})->throws(InvalidArgumentException::class);

it('renders a machine-friendly rufiyaa string to two decimals', function (int $laari, string $expected) {
    expect(Money::fromLaari($laari)->toRufiyaa())->toBe($expected);
})->with([
    [106_000, '1060.00'],
    [53, '0.53'],
    [-5_000, '-50.00'],
    [0, '0.00'],
]);

it('formats for display with the MVR prefix and thousands separators', function (int $laari, string $expected) {
    expect(Money::fromLaari($laari)->format())->toBe($expected);
})->with([
    [106_000, 'MVR 1,060.00'],
    [53, 'MVR 0.53'],
    [-5_000, 'MVR -50.00'],
    [123_456_789, 'MVR 1,234,567.89'],
]);

it('adds, subtracts and multiplies in integer laari', function () {
    $rent = Money::fromLaari(50_000);   // MVR 500
    $fine = Money::fromLaari(105_500);  // MVR 1,055

    expect($rent->add($fine)->laari)->toBe(155_500)                 // total MVR 1,555
        ->and($fine->subtract($rent)->laari)->toBe(55_500)
        ->and(Money::fromLaari(375)->multiply(422)->laari)->toBe(158_250); // 3.75/day × 422 = MVR 1,582.50
});

it('compares equality and zero', function () {
    expect(Money::fromLaari(1_060)->equals(Money::fromRufiyaa('10.60')))->toBeTrue()
        ->and(Money::fromLaari(0)->isZero())->toBeTrue()
        ->and(Money::fromLaari(1)->isZero())->toBeFalse();
});

it('matches the PRD per-ft² worked example', function () {
    // 2,000 ft² × 53 laari = 106,000 laari = MVR 1,060.00 per month.
    $monthlyRent = Money::fromLaari(2_000 * 53);

    expect($monthlyRent->laari)->toBe(106_000)
        ->and($monthlyRent->format())->toBe('MVR 1,060.00');
});
