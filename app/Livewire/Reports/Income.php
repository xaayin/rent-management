<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\Reporting\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Income report (FR-RPT-04): billed vs collected by month, and collections
 * broken down by property usage type and tenant type.
 */
#[Layout('components.layouts.app')]
class Income extends Component
{
    #[Url]
    public int $year = 0;

    public function mount(): void
    {
        if ($this->year < 2000 || $this->year > 2200) {
            $this->year = CarbonImmutable::now(config('app.timezone'))->year;
        }
    }

    public function render(ReportService $reports): View
    {
        $months = $reports->incomeByMonth($this->year);

        return view('livewire.reports.income', [
            'months' => $months,
            'totalBilled' => (int) $months->sum('billed_laari'),
            'totalCollected' => (int) $months->sum('collected_laari'),
            'byPropertyType' => $reports->incomeByPropertyType($this->year),
            'byTenantType' => $reports->incomeByTenantType($this->year),
            'years' => range((int) now(config('app.timezone'))->year, (int) now(config('app.timezone'))->year - 5),
        ]);
    }
}
