<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\Portal\PortalPdfController;
use App\Http\Controllers\ReceiptPdfController;
use App\Http\Controllers\ReportExportController;
use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Livewire\Dashboard\Index as DashboardIndex;
use App\Livewire\FollowUps\Index as FollowUpsIndex;
use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Livewire\Leases\Index as LeasesIndex;
use App\Livewire\Leases\Show as LeaseShow;
use App\Livewire\Portal\Home as PortalHome;
use App\Livewire\Portal\Login as PortalLogin;
use App\Livewire\Properties\Index as PropertiesIndex;
use App\Livewire\Reports\Arrears as ArrearsReport;
use App\Livewire\Reports\Income as IncomeReport;
use App\Livewire\Settings\Profile as ProfileSettings;
use App\Livewire\Settings\Reminders as ReminderSettings;
use App\Livewire\Settings\UserManagement;
use App\Livewire\Tenants\Index as TenantsIndex;
use App\Livewire\Tenants\Statement as TenantStatement;
use App\Livewire\Transfers\Index as TransfersIndex;
use App\Models\ApprovalRequest;
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

    // The lease workspace gets its own URL — bookmarkable, linkable, and
    // survivable across a browser tab (design PRD §4.2).
    Route::get('/leases/{lease}', LeaseShow::class)
        ->middleware('can:'.Permission::ManageLeases->value)
        ->name('leases.show');

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

    // Gated on the policy, not a single permission: the inbox is for whoever
    // may decide at least one §6.1 `A` action (see ApprovalRequestPolicy).
    Route::get('/approvals', ApprovalsIndex::class)
        ->middleware('can:viewAny,'.ApprovalRequest::class)
        ->name('approvals.index');

    // The Finance queue of tenant bank-transfer claims (T3), same permission
    // as recording a payment — confirming a claim records one.
    // The arrears chasing worklist (R2) — collectors, not readers.
    Route::get('/follow-ups', FollowUpsIndex::class)
        ->middleware('can:'.Permission::RecordPayments->value)
        ->name('follow-ups.index');

    Route::get('/transfers', TransfersIndex::class)
        ->middleware('can:'.Permission::RecordPayments->value)
        ->name('transfers.index');

    Route::get('/settings/users', UserManagement::class)
        ->middleware('can:'.Permission::ManageUsers->value)
        ->name('settings.users');

    Route::get('/settings/reminders', ReminderSettings::class)
        ->middleware('can:'.Permission::ConfigureNotifications->value)
        ->name('settings.reminders');
});

/*
|--------------------------------------------------------------------------
| Tenant portal (T2) — its own guard, its own front door
|--------------------------------------------------------------------------
| Read-only self-service for tenants, signed in by SMS one-time code. Every
| route is throttled: this is the application's only public-facing surface.
*/
Route::prefix('portal')->middleware('throttle:30,1')->group(function (): void {
    Route::get('/login', PortalLogin::class)->name('portal.login');

    Route::middleware('auth:tenant')->group(function (): void {
        Route::get('/', PortalHome::class)->name('portal.home');

        Route::get('/invoices/{invoice}/pdf', [PortalPdfController::class, 'invoice'])
            ->name('portal.invoices.pdf');

        Route::get('/receipts/{receipt}/pdf', [PortalPdfController::class, 'receipt'])
            ->name('portal.receipts.pdf');
    });
});
