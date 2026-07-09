<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lease;
use App\Services\Billing\InvoiceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the current cycle's invoices for every active lease (FR-INV-01).
 * If generation fails for one lease the error is logged and the run continues
 * with the others (FR-INV-09). Safe to re-run — generation is idempotent.
 */
class GenerateInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string|null  $period  Billing month as YYYY-MM (or any parseable
     *                               date); defaults to the current month.
     */
    public function __construct(public ?string $period = null) {}

    public function handle(InvoiceGenerator $generator): void
    {
        $period = ($this->period !== null
            ? CarbonImmutable::parse($this->period)
            : CarbonImmutable::now(config('app.timezone')))->startOfMonth();

        Lease::query()->active()->each(function (Lease $lease) use ($generator, $period): void {
            try {
                $generator->generate($lease, $period);
            } catch (Throwable $e) {
                Log::error('Invoice generation failed for lease.', [
                    'lease_id' => $lease->id,
                    'period' => $period->toDateString(),
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
