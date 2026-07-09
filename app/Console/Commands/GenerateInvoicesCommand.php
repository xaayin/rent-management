<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateInvoices;
use Illuminate\Console\Command;

/**
 * Manually trigger invoice generation for a billing month (FR-INV-05).
 */
class GenerateInvoicesCommand extends Command
{
    protected $signature = 'invoices:generate {--period= : Billing month as YYYY-MM; defaults to the current month}';

    protected $description = 'Generate invoices for all active leases for a billing month.';

    public function handle(): int
    {
        GenerateInvoices::dispatchSync($this->option('period'));

        $this->info('Invoice generation complete.');

        return self::SUCCESS;
    }
}
