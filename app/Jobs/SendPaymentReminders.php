<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Reminders\ReminderDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the day's scheduled payment reminders (FR-NOT-02). Runs daily after
 * the fine refresh so amounts quoted are current; safe to re-run — sends are
 * deduplicated per invoice and kind (FR-NOT-09).
 */
class SendPaymentReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string|null  $date  The day to run for (parseable date);
     *                             defaults to today in the app timezone.
     */
    public function __construct(public ?string $date = null) {}

    public function handle(ReminderDispatcher $dispatcher): void
    {
        $today = $this->date !== null
            ? CarbonImmutable::parse($this->date)
            : CarbonImmutable::now(config('app.timezone'));

        $dispatcher->dispatchForDate($today);
    }
}
