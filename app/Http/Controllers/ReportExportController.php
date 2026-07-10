<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Reporting\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV and PDF exports of the reports (FR-RPT-05). CSV opens directly in Excel;
 * amounts are exported as plain decimal MVR.
 */
class ReportExportController extends Controller
{
    public function arrears(string $format, ReportService $reports): mixed
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $rows = $reports->arrears($today);

        if ($format === 'pdf') {
            return Pdf::view('pdf.arrears-report', ['rows' => $rows, 'today' => $today])
                ->format(Format::A4)
                ->inline('arrears-'.$today->toDateString().'.pdf');
        }

        return $this->csv('arrears-'.$today->toDateString().'.csv', function ($out) use ($rows): void {
            fputcsv($out, ['Invoice', 'Tenant', 'Property', 'Due date', 'Days overdue', 'Current fine (MVR)', 'Outstanding (MVR)']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['invoice']->number,
                    $row['invoice']->lease->tenant->name,
                    $row['invoice']->lease->property->name,
                    $row['invoice']->due_date->toDateString(),
                    $row['days_overdue'],
                    Money::fromLaari($row['outstanding_fine_laari'])->toRufiyaa(),
                    Money::fromLaari($row['outstanding_laari'])->toRufiyaa(),
                ]);
            }
        });
    }

    public function income(string $format, Request $request, ReportService $reports): mixed
    {
        $year = (int) $request->query('year', (string) now(config('app.timezone'))->year);
        abort_unless($year >= 2000 && $year <= 2200, 404);

        $months = $reports->incomeByMonth($year);

        if ($format === 'pdf') {
            return Pdf::view('pdf.income-report', [
                'year' => $year,
                'months' => $months,
                'byPropertyType' => $reports->incomeByPropertyType($year),
                'byTenantType' => $reports->incomeByTenantType($year),
            ])->format(Format::A4)->inline("income-{$year}.pdf");
        }

        return $this->csv("income-{$year}.csv", function ($out) use ($months): void {
            fputcsv($out, ['Month', 'Billed (MVR)', 'Collected (MVR)']);

            foreach ($months as $row) {
                fputcsv($out, [
                    date('F', mktime(0, 0, 0, $row['month'], 1)),
                    Money::fromLaari($row['billed_laari'])->toRufiyaa(),
                    Money::fromLaari($row['collected_laari'])->toRufiyaa(),
                ]);
            }

            fputcsv($out, [
                'Total',
                Money::fromLaari((int) $months->sum('billed_laari'))->toRufiyaa(),
                Money::fromLaari((int) $months->sum('collected_laari'))->toRufiyaa(),
            ]);
        });
    }

    private function csv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $out = fopen('php://output', 'w');
            $writer($out);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
