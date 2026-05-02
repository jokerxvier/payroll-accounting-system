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
});

require __DIR__.'/settings.php';
