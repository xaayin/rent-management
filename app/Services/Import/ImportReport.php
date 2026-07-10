<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\Money;

/**
 * The reconciliation report of one import run (PRD §11.3 migration practice):
 * created/updated counts per entity, the total monthly rent of the imported
 * leases (to tick against the workbook), and every rejected row with its
 * reasons.
 */
final class ImportReport
{
    /** @var array<string, array{created: int, updated: int}> */
    public array $entities = [
        'properties' => ['created' => 0, 'updated' => 0],
        'tenants' => ['created' => 0, 'updated' => 0],
        'leases' => ['created' => 0, 'updated' => 0],
        'fine_rules' => ['created' => 0, 'updated' => 0],
    ];

    /** @var list<array{line: int, reference: string, reasons: list<string>}> */
    public array $rejected = [];

    /** @var list<array{line: int, reference: string, message: string}> */
    public array $warnings = [];

    public int $rowsProcessed = 0;

    public int $monthlyRentLaari = 0;

    public bool $dryRun = false;

    public function count(string $entity, bool $created): void
    {
        $this->entities[$entity][$created ? 'created' : 'updated']++;
    }

    /**
     * @param  list<string>  $reasons
     */
    public function reject(int $line, string $reference, array $reasons): void
    {
        $this->rejected[] = ['line' => $line, 'reference' => $reference, 'reasons' => $reasons];
    }

    public function warn(int $line, string $reference, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'reference' => $reference, 'message' => $message];
    }

    public function imported(): int
    {
        return $this->rowsProcessed - count($this->rejected);
    }

    public function monthlyRent(): Money
    {
        return Money::fromLaari($this->monthlyRentLaari);
    }
}
