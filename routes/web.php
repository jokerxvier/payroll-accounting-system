<?php

use App\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/{staffId}', [EmployeeController::class, 'show'])
        ->whereNumber('staffId')->name('employees.show');
    Route::post('employees/{staffId}/profile', [EmployeeController::class, 'store'])
        ->whereNumber('staffId')->name('employees.profile.store');
    Route::patch('employees/{staffId}/profile', [EmployeeController::class, 'update'])
        ->whereNumber('staffId')->name('employees.profile.update');
    Route::get('employees/{staffId}/profile/json', [EmployeeController::class, 'profileJson'])
        ->whereNumber('staffId')->name('employees.profile.json');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
