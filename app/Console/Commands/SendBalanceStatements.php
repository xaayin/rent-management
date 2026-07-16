<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Enums\ReminderKind;
use App\Models\NotificationLog;
use App\Models\ReminderRule;
use App\Models\Tenant;
use App\Notifications\BalanceStatement;
use App\Services\Reminders\TemplateRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The monthly "your balance with the council is X" text to every tenant in
 * arrears (T1) — a statement, not a demand. Scheduled on the 1st; idempotent
 * within a calendar month, so re-runs (or a crashed run resumed) never nag a
 * tenant twice. One tenant's failure never stops the rest.
 */
class SendBalanceStatements extends Command
{
    protected $signature = 'tenants:send-balance-statements';

    protected $description = 'SMS every tenant in arrears their outstanding balance (once per calendar month)';

    public function handle(TemplateRenderer $renderer): int
    {
        $asOf = CarbonImmutable::parse(today()->toDateString());

        $rule = ReminderRule::query()
            ->where('kind', ReminderKind::BalanceStatement->value)
            ->where('enabled', true)
            ->first();

        if ($rule === null) {
            $this->info('Balance statements are disabled — nothing sent.');

            return self::SUCCESS;
        }

        $sent = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($renderer, $rule, $asOf, &$sent): void {
            try {
                if (! $tenant->canReceiveSms()) {
                    return;
                }

                if (! $tenant->outstandingBalance()->isPositive()) {
                    return;
                }

                if ($this->alreadySentThisMonth($tenant, $asOf)) {
                    return;
                }

                $tenant->notify(new BalanceStatement($renderer->renderTenant($rule->template, $tenant)));
                $sent++;
            } catch (Throwable $e) {
                Log::error('Balance statement failed for tenant.', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $this->info("Balance statements sent: {$sent}.");

        return self::SUCCESS;
    }

    private function alreadySentThisMonth(Tenant $tenant, CarbonImmutable $asOf): bool
    {
        return NotificationLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', ReminderKind::BalanceStatement->value)
            ->where('status', NotificationStatus::Sent->value)
            ->whereBetween('created_at', [$asOf->startOfMonth(), $asOf->endOfMonth()])
            ->exists();
    }
}
