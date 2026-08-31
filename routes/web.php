<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\Employees\EmployeeAllowanceController;
use App\Http\Controllers\Employees\EmployeeDeductionController;
use App\Http\Controllers\Employees\EmployeeLoanController;
use App\Http\Controllers\Employees\PayrollAdjustmentController;
use App\Http\Controllers\PayrollPreviewController;
use App\Http\Controllers\Public\InvoicePaymentController;
use App\Http\Controllers\Webhooks\GatewayWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

/*
 * Customer-facing payment routes — the first guest-reachable application
 * routes in this app, and the first unauthenticated POST.
 *
 * The `/schools/{slug}/` prefix is not decoration: it is what lets
 * SchoolTenantFinder resolve the school on a request that carries no session
 * and no user. A gateway posting to a single fixed URL would 404 at
 * NeedsTenant before reaching any controller.
 *
 * The slug is NOT the credential. For a guest, ApplyTenantOverride's
 * LMS-pinning does not run, so a crafted slug resolves whatever it names —
 * which is why every lookup past this point matches on school AND the
 * invoice's own unguessable pay token together.
 */
Route::prefix('schools/{slug}')->group(function () {
    Route::get('pay/{token}', [InvoicePaymentController::class, 'show'])
        ->name('public.pay.show');
    Route::post('pay/{token}/checkout', [InvoicePaymentController::class, 'checkout'])
        ->name('public.pay.checkout');
    Route::get('pay/{token}/return', [InvoicePaymentController::class, 'return'])
        ->name('public.pay.return');

    Route::post('webhooks/{provider}', GatewayWebhookController::class)
        ->name('public.webhooks');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('employees/bulk-setup-profiles', [EmployeeController::class, 'bulkSetupMissingProfiles'])
        ->name('employees.bulk-setup-profiles');
    Route::get('employees/{staffId}', [EmployeeController::class, 'show'])
        ->whereNumber('staffId')->name('employees.show');
    Route::post('employees/{staffId}/profile', [EmployeeController::class, 'store'])
        ->whereNumber('staffId')->name('employees.profile.store');
    Route::patch('employees/{staffId}/profile', [EmployeeController::class, 'update'])
        ->whereNumber('staffId')->name('employees.profile.update');
    Route::get('employees/{staffId}/profile/json', [EmployeeController::class, 'profileJson'])
        ->whereNumber('staffId')->name('employees.profile.json');

    // Per-employee subscription management (Week 7, Chunk 6) — write-only nested
    // resources. Read flow goes through `EmployeeController::show` props that
    // populate the deduction / allowance / loan / adjustment cards.
    //
    // Each resource only registers store/update/destroy. The Show page renders
    // the create + edit forms inline via shadcn sheets, so there are no
    // standalone create/edit pages.
    Route::post('employees/{staffId}/deductions', [EmployeeDeductionController::class, 'store'])
        ->whereNumber('staffId')->name('employees.deductions.store');
    Route::patch('employees/{staffId}/deductions/{employeeDeduction}', [EmployeeDeductionController::class, 'update'])
        ->whereNumber('staffId')->whereNumber('employeeDeduction')->name('employees.deductions.update');
    Route::delete('employees/{staffId}/deductions/{employeeDeduction}', [EmployeeDeductionController::class, 'destroy'])
        ->whereNumber('staffId')->whereNumber('employeeDeduction')->name('employees.deductions.destroy');

    Route::post('employees/{staffId}/allowances', [EmployeeAllowanceController::class, 'store'])
        ->whereNumber('staffId')->name('employees.allowances.store');
    Route::patch('employees/{staffId}/allowances/{employeeAllowance}', [EmployeeAllowanceController::class, 'update'])
        ->whereNumber('staffId')->whereNumber('employeeAllowance')->name('employees.allowances.update');
    Route::delete('employees/{staffId}/allowances/{employeeAllowance}', [EmployeeAllowanceController::class, 'destroy'])
        ->whereNumber('staffId')->whereNumber('employeeAllowance')->name('employees.allowances.destroy');

    Route::post('employees/{staffId}/loans', [EmployeeLoanController::class, 'store'])
        ->whereNumber('staffId')->name('employees.loans.store');
    Route::patch('employees/{staffId}/loans/{employeeLoan}', [EmployeeLoanController::class, 'update'])
        ->whereNumber('staffId')->whereNumber('employeeLoan')->name('employees.loans.update');
    Route::delete('employees/{staffId}/loans/{employeeLoan}', [EmployeeLoanController::class, 'destroy'])
        ->whereNumber('staffId')->whereNumber('employeeLoan')->name('employees.loans.destroy');

    Route::post('employees/{staffId}/adjustments', [PayrollAdjustmentController::class, 'store'])
        ->whereNumber('staffId')->name('employees.adjustments.store');
    Route::patch('employees/{staffId}/adjustments/{payrollAdjustment}', [PayrollAdjustmentController::class, 'update'])
        ->whereNumber('staffId')->whereNumber('payrollAdjustment')->name('employees.adjustments.update');
    Route::delete('employees/{staffId}/adjustments/{payrollAdjustment}', [PayrollAdjustmentController::class, 'destroy'])
        ->whereNumber('staffId')->whereNumber('payrollAdjustment')->name('employees.adjustments.destroy');

    // Week 8 — real-time gross-to-net payroll preview. Both endpoints render
    // the same Inertia page; compute() round-trips a populated computation
    // result and prior-period comparison via the same component.
    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/preview', [PayrollPreviewController::class, 'show'])
            ->name('preview.show');
        Route::post('/preview/compute', [PayrollPreviewController::class, 'compute'])
            ->name('preview.compute');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
