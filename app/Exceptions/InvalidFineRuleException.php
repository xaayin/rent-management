<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A fine period that would make "which rule applies" ambiguous, or that would
 * rewrite a period already in force. Money must have exactly one answer.
 */
class InvalidFineRuleException extends RuntimeException
{
    public static function endsBeforeItStarts(): self
    {
        return new self('This period ends before it starts — check the two dates.');
    }

    public static function overlaps(string $periodLabel): self
    {
        return new self(
            'This period overlaps an existing one ('.$periodLabel.'). '
            .'End that period first, or choose dates that sit in a free gap.'
        );
    }

    public static function closedBeforeStart(string $startLabel): self
    {
        return new self('A period cannot end before it began on '.$startLabel.'.');
    }

    public static function alreadyClosed(string $periodLabel): self
    {
        return new self('That period already ended ('.$periodLabel.').');
    }

    /**
     * A period that any invoice falls inside is what those invoices' fines are
     * recomputed from every night. Rewriting or erasing it would change money
     * already demanded, so the only correct move is to end it and start afresh.
     */
    public static function cannotEdit(int $invoiceCount): self
    {
        return new self(self::coveringInvoices('edited', $invoiceCount));
    }

    public static function cannotRemove(int $invoiceCount): self
    {
        return new self(self::coveringInvoices('deleted', $invoiceCount));
    }

    private static function coveringInvoices(string $verb, int $invoiceCount): string
    {
        return sprintf(
            'This period covers %d invoice%s, so it cannot be %s — end it and start a new period instead.',
            $invoiceCount,
            $invoiceCount === 1 ? '' : 's',
            $verb,
        );
    }
}
