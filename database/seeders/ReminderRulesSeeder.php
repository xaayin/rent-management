<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReminderKind;
use App\Models\ReminderRule;
use Illuminate\Database\Seeder;

/**
 * The default reminder schedule (FR-NOT-02) and English templates
 * (FR-NOT-03). Editable by an Administrator under Settings → Reminders.
 */
class ReminderRulesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'kind' => ReminderKind::PreDue->value,
                'days' => 3,
                'template' => 'Dear {tenant}, rent of {amount_due} for {property} ({period}) falls due on {due_date}. '
                    .'Invoice {invoice_number}. Please pay to {payment_account}.',
            ],
            [
                'kind' => ReminderKind::OnDue->value,
                'days' => 0,
                'template' => 'Dear {tenant}, rent of {amount_due} for {property} ({period}) is due today. '
                    .'Invoice {invoice_number}. Please pay to {payment_account}.',
            ],
            [
                'kind' => ReminderKind::Overdue->value,
                'days' => 3,
                'template' => 'Dear {tenant}, invoice {invoice_number} for {property} ({period}) is overdue. '
                    .'Amount now due {amount_due} (includes fine {fine}). Please pay to {payment_account} to avoid further fines.',
            ],
        ];

        foreach ($defaults as $rule) {
            ReminderRule::query()->firstOrCreate(
                ['kind' => $rule['kind']],
                ['enabled' => true, 'days' => $rule['days'], 'template' => $rule['template']],
            );
        }
    }
}
