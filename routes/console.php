<?php

declare(strict_types=1);

use App\Jobs\GenerateInvoices;
use App\Jobs\SendPaymentReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-derive Expired status for leases past their expiry date (FR-LSE-03).
Schedule::command('leases:expire')->dailyAt('00:05');

// Generate the month's invoices for every active lease (FR-INV-01).
Schedule::job(new GenerateInvoices)->monthlyOn(1, '00:10');

// Recompute accruing fines on unpaid overdue invoices daily (FR-FIN-05).
Schedule::command('invoices:refresh-fines')->dailyAt('00:15');

// Send the day's payment reminders at a civil hour, after the fine refresh so
// the amounts quoted are current (FR-NOT-02, §5.5).
Schedule::job(new SendPaymentReminders)->dailyAt('09:00');

// Monthly balance statement to every tenant in arrears (T1) — after the 1st's
// invoice generation and fine refresh, so the quoted balance includes the new
// month. Idempotent within the month.
Schedule::command('tenants:send-balance-statements')->monthlyOn(1, '09:05');
