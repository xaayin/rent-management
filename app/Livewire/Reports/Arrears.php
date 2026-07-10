<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Livewire\Concerns\InteractsWithPayments;
use App\Services\Reporting\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Arrears / aging report (FR-RPT-02): every overdue invoice with days overdue
 * and the current fine, plus the one-click reminder (design PRD §6 "Arrears").
 */
#[Layout('components.layouts.app')]
class Arrears extends Component
{
    use InteractsWithPayments;

    public function render(ReportService $reports): View
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $rows = $reports->arrears($today);

        return view('livewire.reports.arrears', [
            'rows' => $rows,
            'totalOutstanding' => Money::fromLaari((int) $rows->sum('outstanding_laari')),
            'totalFines' => Money::fromLaari((int) $rows->sum('outstanding_fine_laari')),
        ]);
    }
}
