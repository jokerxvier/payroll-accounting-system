<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AllowanceController;
use App\Http\Controllers\Admin\DeductionTypeController;
use App\Http\Controllers\Admin\PayrollRunController;
use App\Http\Controllers\Admin\StatutoryContributionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('contribution-tables', [StatutoryContributionController::class, 'index'])
            ->name('contribution-tables.index');
        Route::get('contribution-tables/create', [StatutoryContributionController::class, 'create'])
            ->name('contribution-tables.create');
        Route::post('contribution-tables', [StatutoryContributionController::class, 'store'])
            ->name('contribution-tables.store');

        // {contribution} routes are registered AFTER `create` so the static
        // segment wins the match. Order is load-bearing — moving these above
        // `create` would silently route GET /contribution-tables/create to
        // show() with the literal string "create" as the bound model.
        Route::get('contribution-tables/{contribution}', [StatutoryContributionController::class, 'show'])
            ->name('contribution-tables.show');
        Route::get('contribution-tables/{contribution}/edit', [StatutoryContributionController::class, 'edit'])
            ->name('contribution-tables.edit');
        Route::patch('contribution-tables/{contribution}', [StatutoryContributionController::class, 'update'])
            ->name('contribution-tables.update');
        Route::post('contribution-tables/{contribution}/void', [StatutoryContributionController::class, 'void'])
            ->name('contribution-tables.void');

        // Week 7 catalog admin — DeductionType (resource controller minus show
        // since the index doubles as the catalog overview). The kebab-case URI
        // is mapped back to the camelCase `deductionType` route parameter so
        // the controller can keep the conventional variable name without
        // litter from kebab-case in PHP code.
        Route::resource('deduction-types', DeductionTypeController::class)
            ->parameters(['deduction-types' => 'deductionType'])
            ->except(['show']);

        // Week 7 catalog admin — Allowance. Default singular `allowance`
        // parameter is fine; no parameters() override needed.
        Route::resource('allowances', AllowanceController::class)
            ->except(['show']);

        // Phase 3 Week 9 — Payroll runs (batch generate / show / list).
        // Approval + voiding land in Week 10; not exposed yet.
        Route::get('payroll-runs', [PayrollRunController::class, 'index'])
            ->name('payroll-runs.index');
        Route::get('payroll-runs/create', [PayrollRunController::class, 'create'])
            ->name('payroll-runs.create');
        Route::post('payroll-runs', [PayrollRunController::class, 'store'])
            ->name('payroll-runs.store');
        Route::get('payroll-runs/{payrollRun}', [PayrollRunController::class, 'show'])
            ->name('payroll-runs.show');
    });
