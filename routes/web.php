<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\ReceiptPdfController;
use App\Http\Controllers\ReportExportController;
use App\Livewire\Dashboard\Index as DashboardIndex;
use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Livewire\Leases\Index as LeasesIndex;
use App\Livewire\Properties\Index as PropertiesIndex;
use App\Livewire\Reports\Arrears as ArrearsReport;
use App\Livewire\Reports\Income as IncomeReport;
use App\Livewire\Settings\Profile as ProfileSettings;
use App\Livewire\Settings\Reminders as ReminderSettings;
use App\Livewire\Settings\UserManagement;
use App\Livewire\Tenants\Index as TenantsIndex;
use App\Livewire\Tenants\Statement as TenantStatement;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth'])->group(function (): void {
    // Every signed-in user manages their own account here (no role gate).
    Route::get('/settings/profile', ProfileSettings::class)->name('settings.profile');

    Route::get('/dashboard', DashboardIndex::class)
        ->middleware('can:'.Permission::ViewReports->value)
        ->name('dashboard');

    Route::get('/reports/arrears', ArrearsReport::class)
        ->middleware('can:'.Permission::ViewReports->value)
        ->name('reports.arrears');

    Route::get('/reports/income', IncomeReport::class)
        ->middleware('can:'.Permission::ViewReports->value)
        ->name('reports.income');

    Route::get('/reports/arrears/export/{format}', [ReportExportController::class, 'arrears'])
        ->whereIn('format', ['csv', 'pdf'])
        ->middleware('can:'.Permission::ViewReports->value)
        ->name('reports.arrears.export');

    Route::get('/reports/income/export/{format}', [ReportExportController::class, 'income'])
        ->whereIn('format', ['csv', 'pdf'])
        ->middleware('can:'.Permission::ViewReports->value)
        ->name('reports.income.export');

    Route::get('/properties', PropertiesIndex::class)
        ->middleware('can:'.Permission::ManageProperties->value)
        ->name('properties.index');

    Route::get('/tenants', TenantsIndex::class)
        ->middleware('can:'.Permission::ManageTenants->value)
        ->name('tenants.index');

    Route::get('/leases', LeasesIndex::class)
        ->middleware('can:'.Permission::ManageLeases->value)
        ->name('leases.index');

    Route::get('/invoices', InvoicesIndex::class)
        ->middleware('can:'.Permission::IssueInvoices->value)
        ->name('invoices.index');

    Route::get('/tenants/{tenant}/statement', TenantStatement::class)
        ->middleware('can:'.Permission::ViewReports->value)
        ->name('tenants.statement');

    Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)
        ->middleware('can:'.Permission::ViewReports->value)
        ->name('invoices.pdf');

    Route::get('/payments/{payment}/receipt', ReceiptPdfController::class)
        ->middleware('can:'.Permission::ViewReports->value)
        ->name('payments.receipt');

    Route::get('/settings/users', UserManagement::class)
        ->middleware('can:'.Permission::ManageUsers->value)
        ->name('settings.users');

    Route::get('/settings/reminders', ReminderSettings::class)
        ->middleware('can:'.Permission::ConfigureNotifications->value)
        ->name('settings.reminders');
});
