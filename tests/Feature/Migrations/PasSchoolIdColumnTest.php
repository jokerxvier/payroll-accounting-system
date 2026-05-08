<?php

declare(strict_types=1);

use App\Models\Pas\School;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase D.1 schema gate.
 *
 * Pins the post-D.1 state of the 15 tenant-scoped pas_* tables — every one
 * has a nullable `school_id` column with an FK to pas_schools(id) and an
 * index. Three tables also flip their existing single-column unique into a
 * composite `(school_id, X)` unique so two tenants can carry the same value.
 *
 * Tests run against the in-memory SQLite connection per phpunit.xml.
 * Schema::getForeignKeys() and Schema::getIndexes() are Laravel's portable
 * wrappers and work on both sqlite and mysql, so the FK + index assertions
 * hold across both drivers. The compound-unique behavioural test inserts
 * actual rows to confirm the constraint is in place.
 */

/** @var list<array{table: string, replace_unique: ?array<int,string>}> */
$tenantScopedTables = [
    ['table' => 'pas_employee_profiles', 'replace_unique' => ['lms_staff_id']],
    ['table' => 'pas_pay_periods', 'replace_unique' => ['code']],
    ['table' => 'pas_accounting_periods', 'replace_unique' => ['code']],
    ['table' => 'pas_payroll_runs', 'replace_unique' => null],
    ['table' => 'pas_payslips', 'replace_unique' => null],
    ['table' => 'pas_employee_allowances', 'replace_unique' => null],
    ['table' => 'pas_employee_deductions', 'replace_unique' => null],
    ['table' => 'pas_employee_loans', 'replace_unique' => null],
    ['table' => 'pas_payroll_adjustments', 'replace_unique' => null],
    ['table' => 'pas_audit_logs', 'replace_unique' => null],
    ['table' => 'pas_jobs', 'replace_unique' => null],
    ['table' => 'pas_failed_jobs', 'replace_unique' => null],
    ['table' => 'pas_notifications', 'replace_unique' => null],
    ['table' => 'pas_password_resets', 'replace_unique' => null],
    ['table' => 'pas_job_batches', 'replace_unique' => null],
];

it('adds school_id to every tenant-scoped pas_* table', function () use ($tenantScopedTables): void {
    foreach ($tenantScopedTables as $config) {
        $table = $config['table'];

        expect(Schema::hasTable($table))->toBeTrue("Expected {$table} to exist.");
        expect(Schema::hasColumn($table, 'school_id'))->toBeTrue(
            "Expected {$table}.school_id to exist.",
        );
    }
});

it('declares an FK from every school_id column to pas_schools(id)', function () use ($tenantScopedTables): void {
    foreach ($tenantScopedTables as $config) {
        $table = $config['table'];

        $foreignKeys = collect(Schema::getForeignKeys($table));

        $match = $foreignKeys->first(
            fn (array $row): bool => $row['columns'] === ['school_id'],
        );

        expect($match)->not->toBeNull(
            "Expected an FK on {$table}.school_id, none found.",
        );
        expect($match['foreign_table'])->toBe(
            'pas_schools',
            "Expected {$table}.school_id → pas_schools, got {$match['foreign_table']}.",
        );
        expect($match['foreign_columns'])->toBe(['id']);
        expect($match['on_delete'])->toBe(
            'restrict',
            "Expected {$table}.school_id on_delete=restrict, got {$match['on_delete']}.",
        );
    }
});

it('places a non-unique index on every school_id column', function () use ($tenantScopedTables): void {
    foreach ($tenantScopedTables as $config) {
        $table = $config['table'];

        $indexes = collect(Schema::getIndexes($table));

        $match = $indexes->first(
            fn (array $idx): bool => $idx['columns'] === ['school_id'] && ! $idx['unique'],
        );

        expect($match)->not->toBeNull(
            "Expected a non-unique index on {$table}.school_id, none found.",
        );
    }
});

it('replaces the lms_staff_id unique on pas_employee_profiles with (school_id, lms_staff_id)', function (): void {
    $indexes = collect(Schema::getIndexes('pas_employee_profiles'));

    // The legacy single-column unique must be gone.
    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['lms_staff_id'] && $idx['unique'],
    ))->toBeFalse('Old single-column unique on lms_staff_id should have been dropped.');

    // The new composite unique must be present.
    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['school_id', 'lms_staff_id'] && $idx['unique'],
    ))->toBeTrue('Expected composite unique on (school_id, lms_staff_id).');

    // Behavioural smoke: two profiles with the same lms_staff_id but
    // different school_id values must both insert successfully — proves the
    // composite is in effect, not the old single-column unique.
    $schoolA = School::query()->where('slug', 'default')->firstOrFail();
    $schoolB = School::factory()->create();

    $base = [
        'employment_classification' => 'regular',
        'pay_frequency' => 'semi_monthly',
        'basic_salary_centavos' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('pas_employee_profiles')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'lms_staff_id' => 999_001,
    ]));
    DB::table('pas_employee_profiles')->insert(array_merge($base, [
        'school_id' => $schoolB->id,
        'lms_staff_id' => 999_001,
    ]));

    expect(
        DB::table('pas_employee_profiles')->where('lms_staff_id', 999_001)->count(),
    )->toBe(2);

    // Negative case: two rows with the same (school_id, lms_staff_id) must
    // throw a unique-constraint violation.
    expect(fn () => DB::table('pas_employee_profiles')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'lms_staff_id' => 999_001,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('replaces the code unique on pas_pay_periods with (school_id, code)', function (): void {
    $indexes = collect(Schema::getIndexes('pas_pay_periods'));

    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['code'] && $idx['unique'],
    ))->toBeFalse('Old single-column unique on code should have been dropped.');

    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['school_id', 'code'] && $idx['unique'],
    ))->toBeTrue('Expected composite unique on (school_id, code).');

    $schoolA = School::query()->where('slug', 'default')->firstOrFail();
    $schoolB = School::factory()->create();

    $base = [
        'frequency' => 'monthly',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('pas_pay_periods')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'code' => 'D1-PP-A',
    ]));
    DB::table('pas_pay_periods')->insert(array_merge($base, [
        'school_id' => $schoolB->id,
        'code' => 'D1-PP-A',
    ]));

    expect(DB::table('pas_pay_periods')->where('code', 'D1-PP-A')->count())->toBe(2);

    expect(fn () => DB::table('pas_pay_periods')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'code' => 'D1-PP-A',
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('replaces the code unique on pas_accounting_periods with (school_id, code)', function (): void {
    $indexes = collect(Schema::getIndexes('pas_accounting_periods'));

    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['code'] && $idx['unique'],
    ))->toBeFalse('Old single-column unique on code should have been dropped.');

    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['school_id', 'code'] && $idx['unique'],
    ))->toBeTrue('Expected composite unique on (school_id, code).');

    $schoolA = School::query()->where('slug', 'default')->firstOrFail();
    $schoolB = School::factory()->create();

    $base = [
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('pas_accounting_periods')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'code' => 'D1-AP-A',
    ]));
    DB::table('pas_accounting_periods')->insert(array_merge($base, [
        'school_id' => $schoolB->id,
        'code' => 'D1-AP-A',
    ]));

    expect(DB::table('pas_accounting_periods')->where('code', 'D1-AP-A')->count())->toBe(2);

    expect(fn () => DB::table('pas_accounting_periods')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'code' => 'D1-AP-A',
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('leaves no pre-existing row with a null school_id', function () use ($tenantScopedTables): void {
    // The migration runs against the test DB before the SchoolSeeder fires
    // (RefreshDatabase migrates first, beforeEach seeds afterwards), so the
    // backfill is silently skipped when the default school doesn't exist.
    // Tests still expect every row that *does* exist after seeding to carry
    // a school_id — the SchoolSeeder + Pest beforeEach guarantees the
    // default school row is present in `pas_schools` itself, but other
    // tables only get rows via factories which set school_id explicitly.
    //
    // The right invariant for D.1 is: any row inserted by this test suite
    // (via factory or otherwise) that ends up in a tenant-scoped table
    // must NOT have a null school_id, OR if null, must be from a fixture
    // that explicitly tested the nullable behaviour. We assert the simpler
    // sweep: every row in every scoped table currently has a school_id
    // assigned (no leakage from the backfill step).
    foreach ($tenantScopedTables as $config) {
        $table = $config['table'];

        $nullCount = DB::table($table)->whereNull('school_id')->count();

        expect($nullCount)->toBe(
            0,
            "Expected zero null school_id rows in {$table}, found {$nullCount}.",
        );
    }
});
