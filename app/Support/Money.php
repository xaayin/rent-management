<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * An immutable monetary value stored as integer minor units (laari).
 *
 * 1 rufiyaa (MVR) = 100 laari. Money is NEVER held as a float: all arithmetic
 * happens in integer laari and amounts are formatted to MVR only at the
 * view/PDF/SMS boundary. See CLAUDE.md → Hard rules.
 */
final class Money
{
    private function __construct(
        public readonly int $laari,
    ) {}

    /**
     * Build directly from a laari (minor-unit) amount.
     */
    public static function fromLaari(int $laari): self
    {
        return new self($laari);
    }

    /**
     * Build from a rufiyaa amount expressed as a whole integer or a decimal
     * string (e.g. 1060, "1060.00", "0.53"). Parsing is done on the string
     * representation to avoid any floating-point representation error; fractions
     * finer than one laari are rounded half-up.
     */
    public static function fromRufiyaa(int|string $amount): self
    {
        $string = is_int($amount) ? (string) $amount : trim($amount);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            throw new InvalidArgumentException("Invalid rufiyaa amount: [{$amount}].");
        }

        $negative = str_starts_with($string, '-');
        $string = ltrim($string, '-');

        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');

        // Pad so we always have at least the two laari digits plus a rounding digit.
        $fraction = str_pad($fraction, 3, '0');
        $laari = (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $laari++;
        }

        $laari += (int) $whole * 100;

        return new self($negative ? -$laari : $laari);
    }

    public function add(self $other): self
    {
        return new self($this->laari + $other->laari);
    }

    public function subtract(self $other): self
    {
        return new self($this->laari - $other->laari);
    }

    public function multiply(int $factor): self
    {
        return new self($this->laari * $factor);
    }

    public function negate(): self
    {
        return new self(-$this->laari);
    }

    public function absolute(): self
    {
        return new self(abs($this->laari));
    }

    public function equals(self $other): bool
    {
        return $this->laari === $other->laari;
    }

    public function isZero(): bool
    {
        return $this->laari === 0;
    }

    public function isPositive(): bool
    {
        return $this->laari > 0;
    }

    public function isNegative(): bool
    {
        return $this->laari < 0;
    }

    /**
     * The amount as a plain decimal rufiyaa string to two places, e.g. "1060.00".
     * Machine-friendly (no separators) — use format() for display.
     */
    public function toRufiyaa(): string
    {
        $absolute = abs($this->laari);
        $rufiyaa = intdiv($absolute, 100);
        $laari = $absolute % 100;

        return ($this->laari < 0 ? '-' : '')
            .$rufiyaa.'.'.str_pad((string) $laari, 2, '0', STR_PAD_LEFT);
    }

    /**
     * The amount for display, e.g. "MVR 1,060.00".
     */
    public function format(): string
    {
        $absolute = abs($this->laari);
        $rufiyaa = intdiv($absolute, 100);
        $laari = $absolute % 100;

        return 'MVR '.($this->laari < 0 ? '-' : '')
            .number_format($rufiyaa).'.'.str_pad((string) $laari, 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
