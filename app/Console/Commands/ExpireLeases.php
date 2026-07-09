<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LeaseStatus;
use App\Models\Lease;
use Illuminate\Console\Command;

/**
 * Auto-derives Expired status for active leases whose expiry date has passed
 * (FR-LSE-03). Run daily by the scheduler; safe to re-run.
 */
class ExpireLeases extends Command
{
    protected $signature = 'leases:expire';

    protected $description = 'Mark active leases whose expiry date has passed as Expired.';

    public function handle(): int
    {
        $expired = 0;

        Lease::query()
            ->where('status', LeaseStatus::Active->value)
            ->whereDate('expiry_date', '<', today())
            ->get()
            ->each(function (Lease $lease) use (&$expired): void {
                $lease->update(['status' => LeaseStatus::Expired]);
                $expired++;
            });

        $this->info("Expired {$expired} lease(s).");

        return self::SUCCESS;
    }
}
