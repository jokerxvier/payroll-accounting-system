<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AllowanceController;
use App\Http\Controllers\Admin\DeductionTypeController;
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
    });
