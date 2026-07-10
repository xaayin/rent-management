<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/**
 * A register row that cannot be imported; carries every validation reason so
 * the reconciliation report can show them all at once.
 */
class ImportRowException extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' · ', $reasons));
    }
}
