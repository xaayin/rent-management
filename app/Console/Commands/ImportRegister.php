<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\RegisterImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Repeatable legacy-register import (PRD Appendix A, build plan Slice 8).
 * Reads a CSV export of the council's current workbook, upserts by natural
 * keys, and prints a reconciliation report. --dry-run validates and reports
 * without writing anything.
 */
class ImportRegister extends Command
{
    protected $signature = 'import:register {file : Path to the register CSV export}
                            {--dry-run : Validate and report without writing anything}';

    protected $description = 'Import the legacy lease register from a CSV export of the current workbook.';

    public function handle(RegisterImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $importer->import((string) $this->argument('file'), $dryRun);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Import report'.($dryRun ? ' — DRY RUN (nothing was written)' : ''));

        $this->table(
            ['Entity', 'Created', 'Updated'],
            collect($report->entities)->map(fn (array $counts, string $entity) => [
                str_replace('_', ' ', $entity),
                $counts['created'],
                $counts['updated'],
            ])->values()->all(),
        );

        $this->line("Rows processed: {$report->rowsProcessed} · imported: {$report->imported()} · rejected: ".count($report->rejected));
        $this->line('Monthly rent of imported leases: '.$report->monthlyRent()->format().' — reconcile this against the workbook total.');

        if ($report->rejected !== []) {
            $this->newLine();
            $this->warn('Rejected rows (fix in the spreadsheet and re-run — the import is repeatable):');

            $this->table(
                ['Line', 'Reference', 'Reasons'],
                collect($report->rejected)->map(fn (array $row) => [
                    $row['line'],
                    $row['reference'],
                    implode("\n", $row['reasons']),
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
