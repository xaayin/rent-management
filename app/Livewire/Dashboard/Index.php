<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Reporting\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Home dashboard (FR-RPT-01): at-a-glance totals plus the overdue accounts
 * and upcoming expiries that need attention today (design PRD §6.1).
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    public function render(ReportService $reports): View
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $arrears = $reports->arrears($today);

        return view('livewire.dashboard.index', [
            'today' => $today,
            'metrics' => $reports->dashboard($today),
            'needsAttention' => $arrears->take(5),
            'upcoming' => $reports->upcoming($today)->take(5),
        ]);
    }
}
