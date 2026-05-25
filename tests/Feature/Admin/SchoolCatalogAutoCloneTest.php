<?php

declare(strict_types=1);

use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\School;
use App\Observers\SchoolObserver;

/*
 * Stage F of the multi-tenant catalog conversion (auto-clone observer).
 *
 * Pinned behaviours:
 *  - When a new School is created, SchoolObserver::created clones every
 *    Allowance + DeductionType row from the default school to the new one.
 *  - The clone is idempotent: a re-fire on the same school does not duplicate
 *    rows (the observer no-ops when the new school already has rows).
 *  - Cloned rows carry the new-school's clone time as created_at / updated_at,
 *    not the default school's row timestamps.
 *  - The default school's own creation event does NOT clone the catalog onto
 *    itself (no infinite loop, no duplicate of default rows).
 */

beforeEach(function (): void {
    // Wipe any default-school catalog rows the global Pest beforeEach (or a
    // prior test run) might have left behind, so each test starts from a known
    // baseline. Use the unscoped query because the trait would filter to the
    // current tenant.
    Allowance::query()->withoutGlobalScopes()->delete();
    DeductionType::query()->withoutGlobalScopes()->delete();
});

it('clones default school\'s catalogs onto a newly-created school', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    expect($default)->not()->toBeNull();

    // Seed the default school with a known catalog: 3 allowances + 2 deduction
    // types. Tenant-context already pins default via the global beforeEach.
    Allowance::factory()->riceSubsidy()->create();
    Allowance::factory()->transportation()->create();
    Allowance::factory()->meal()->create();

    DeductionType::factory()->hmo()->create();
    DeductionType::factory()->uniform()->create();

    $defaultAllowanceCount = Allowance::query()->count();
    $defaultDeductionCount = DeductionType::query()->count();
    expect($defaultAllowanceCount)->toBe(3);
    expect($defaultDeductionCount)->toBe(2);

    $defaultAllowanceCodes = Allowance::query()->pluck('code')->sort()->values()->all();
    $defaultDeductionCodes = DeductionType::query()->pluck('code')->sort()->values()->all();

    // Create a new school — the observer fires on `created`.
    $newSchool = School::factory()->create(['slug' => 'auto-clone-target']);

    // Switch tenant to the new school and assert the catalog was cloned.
    $newSchool->makeCurrent();

    expect(Allowance::query()->count())->toBe($defaultAllowanceCount);
    expect(DeductionType::query()->count())->toBe($defaultDeductionCount);

    $newAllowanceCodes = Allowance::query()->pluck('code')->sort()->values()->all();
    $newDeductionCodes = DeductionType::query()->pluck('code')->sort()->values()->all();

    expect($newAllowanceCodes)->toEqual($defaultAllowanceCodes);
    expect($newDeductionCodes)->toEqual($defaultDeductionCodes);

    // Every cloned row carries the new school's id, NOT the default's.
    Allowance::query()->each(fn ($row) => expect($row->school_id)->toBe($newSchool->getKey()));
    DeductionType::query()->each(fn ($row) => expect($row->school_id)->toBe($newSchool->getKey()));
});

it('is idempotent — re-firing the observer does not duplicate rows', function (): void {
    Allowance::factory()->riceSubsidy()->create();
    DeductionType::factory()->hmo()->create();

    $newSchool = School::factory()->create(['slug' => 'idempotent-target']);
    $newSchool->makeCurrent();

    $allowanceCountAfterFirstFire = Allowance::query()->count();
    $deductionCountAfterFirstFire = DeductionType::query()->count();

    expect($allowanceCountAfterFirstFire)->toBe(1);
    expect($deductionCountAfterFirstFire)->toBe(1);

    // Re-fire the observer manually (same effect as calling
    // School::observe(...) again on this row, e.g. a developer running a
    // tap-driven seed twice).
    (new SchoolObserver)->created($newSchool);

    expect(Allowance::query()->count())->toBe($allowanceCountAfterFirstFire);
    expect(DeductionType::query()->count())->toBe($deductionCountAfterFirstFire);
});

it('stamps cloned rows with the new-school clone time, not the default\'s row timestamps', function (): void {
    // Backdate the default school's catalog row so we can prove the clone
    // doesn't copy created_at / updated_at across.
    $defaultRow = Allowance::factory()->riceSubsidy()->create();
    $defaultRow->forceFill([
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ])->save();

    $cloneStart = now()->subSecond();

    $newSchool = School::factory()->create(['slug' => 'timestamp-target']);
    $newSchool->makeCurrent();

    $clonedRow = Allowance::query()->first();
    expect($clonedRow)->not()->toBeNull();

    expect($clonedRow->created_at->greaterThanOrEqualTo($cloneStart))->toBeTrue();
    expect($clonedRow->updated_at->greaterThanOrEqualTo($cloneStart))->toBeTrue();
});

it('does not clone the default school\'s catalog onto itself', function (): void {
    // The global Pest beforeEach has already created the default school via
    // SchoolSeeder. Even if the observer fires for that default-school row
    // creation event, no clone should be attempted onto itself. Assert by
    // counting rows in the default school's catalog: it should match
    // exactly what we seeded (no doubles).
    Allowance::factory()->riceSubsidy()->create();
    Allowance::factory()->transportation()->create();

    expect(Allowance::query()->count())->toBe(2);

    // Re-fire the observer on the default school directly.
    $default = School::query()->where('slug', 'default')->first();
    (new SchoolObserver)->created($default);

    // Still 2 — observer noop'd because new->id == default->id.
    expect(Allowance::query()->count())->toBe(2);
});

it('skips silently when the default school does not exist', function (): void {
    // A fresh-bootstrap dev/test env may create a school before the default
    // is seeded (rare, but possible during seeders themselves). The observer
    // must defensively no-op rather than throwing.
    School::query()->where('slug', 'default')->delete();

    // Creating a new school should not throw.
    $school = School::factory()->create(['slug' => 'no-default-target']);
    $school->makeCurrent();

    // Catalog stays empty under the new school's tenant context.
    expect(Allowance::query()->count())->toBe(0);
    expect(DeductionType::query()->count())->toBe(0);
});
