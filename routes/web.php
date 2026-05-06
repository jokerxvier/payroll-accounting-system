<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\Employees\EmployeeAllowanceController;
use App\Http\Controllers\Employees\EmployeeDeductionController;
use App\Http\Controllers\Employees\EmployeeLoanController;
use App\Http\Controllers\Employees\PayrollAdjustmentController;
use App\Http\Controllers\PayrollPreviewController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
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
