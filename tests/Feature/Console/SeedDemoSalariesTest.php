<?php

declare(strict_types=1);

use App\Models\Pas\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns a random round-thousand salary only to zero-salary active rows', function () {
    $zeroSalaryA = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 0,
    ]);
    $zeroSalaryB = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 0,
    ]);
    $unaffected = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 5_000_000, // ₱50,000 already set
    ]);
    EmployeeProfile::factory()->create([
        'is_active' => false,
        'basic_salary_centavos' => 0, // inactive — must be skipped
    ]);

    $this->artisan('payroll:seed-demo-salaries')
        ->expectsOutputToContain('Seeded demo salaries on 2 profiles')
        ->assertSuccessful();

    // Active zero-salary rows are now non-zero and round-thousand pesos.
    //
    // Identified by id, NOT by "salary != 5,000,000". The action picks a
    // random round-thousand between ₱25,000 and ₱75,000, so ₱50,000 is one
    // of the 51 values it can legitimately assign — filtering by value made
    // this test fail whenever a seeded row happened to land on exactly the
    // pre-set figure, roughly 1 - (50/51)^2 ≈ 3.9% of runs.
    $touched = EmployeeProfile::query()
        ->whereKey([$zeroSalaryA->getKey(), $zeroSalaryB->getKey()])
        ->get();

    expect($touched)->toHaveCount(2);
    foreach ($touched as $row) {
        expect($row->basic_salary_centavos)->toBeGreaterThan(0)
            ->and($row->basic_salary_centavos % 100_000)->toBe(0); // round-thousand
    }

    // The pre-set salary stays exactly the same.
    expect($unaffected->fresh()->basic_salary_centavos)->toBe(5_000_000);
});

it('is idempotent — running it twice does not re-seed already-set rows', function () {
    EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 0,
    ]);

    $this->artisan('payroll:seed-demo-salaries')->assertSuccessful();

    $afterFirst = EmployeeProfile::query()
        ->where('is_active', true)
        ->first()
        ?->basic_salary_centavos;

    expect($afterFirst)->toBeGreaterThan(0);

    $this->artisan('payroll:seed-demo-salaries')
        ->expectsOutputToContain('Seeded demo salaries on 0 profiles')
        ->assertSuccessful();

    $afterSecond = EmployeeProfile::query()
        ->where('is_active', true)
        ->first()
        ?->basic_salary_centavos;

    expect($afterSecond)->toBe($afterFirst);
});

it('honours --min and --max range', function () {
    EmployeeProfile::factory()->count(5)->create([
        'is_active' => true,
        'basic_salary_centavos' => 0,
    ]);

    $this->artisan('payroll:seed-demo-salaries', [
        '--min' => 3_000_000,
        '--max' => 4_000_000,
    ])->assertSuccessful();

    $rows = EmployeeProfile::query()->where('is_active', true)->get();
    foreach ($rows as $row) {
        expect($row->basic_salary_centavos)->toBeGreaterThanOrEqual(3_000_000)
            ->and($row->basic_salary_centavos)->toBeLessThanOrEqual(4_000_000);
    }
});
