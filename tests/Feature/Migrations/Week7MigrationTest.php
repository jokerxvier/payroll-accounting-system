<?php

declare(strict_types=1);

use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Week 7 schema gate.
 *
 * Pins the shape of the six new tables introduced in Phase 2 / Week 7
 * (deductions, loans, allowances, adjustments, plus the two catalogs).
 * Any column rename, type change, or removed index in a future migration
 * trips this file before it touches downstream code.
 *
 * Tests run against the in-memory SQLite connection configured in
 * phpunit.xml. SQLite collapses `bigint` and `int` to a single INTEGER
 * affinity and reports `enum(...)` columns as plain `varchar`, so
 * column-type assertions reflect SQLite's reporting rather than the
 * MySQL-precision the migrations actually declare. The MySQL types are
 * captured in code review and via `php artisan db:show` against the
 * payroll_db schema; the production type guarantees come from the
 * migration files themselves, not from this test.
 */

it('creates pas_allowances with the expected columns and indexes', function (): void {
    expect(Schema::hasTable('pas_allowances'))->toBeTrue();

    expect(Schema::hasColumns('pas_allowances', [
        'id', 'code', 'name', 'is_taxable', 'is_de_minimis',
        'de_minimis_cap_centavos', 'default_amount_centavos', 'is_active',
        'notes', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::getColumnType('pas_allowances', 'id'))->toBe('integer');
    expect(Schema::getColumnType('pas_allowances', 'code'))->toBe('varchar');
    expect(Schema::getColumnType('pas_allowances', 'name'))->toBe('varchar');
    expect(Schema::getColumnType('pas_allowances', 'is_taxable'))->toBe('tinyint');
    expect(Schema::getColumnType('pas_allowances', 'is_de_minimis'))->toBe('tinyint');
    // SQLite reports bigint columns as `integer`; MySQL stores `bigint`.
    expect(Schema::getColumnType('pas_allowances', 'de_minimis_cap_centavos'))->toBe('integer');
    expect(Schema::getColumnType('pas_allowances', 'default_amount_centavos'))->toBe('integer');
    expect(Schema::getColumnType('pas_allowances', 'is_active'))->toBe('tinyint');
    expect(Schema::getColumnType('pas_allowances', 'notes'))->toBe('text');

    // Unique on `code` was converted to a composite (school_id, code) unique
    // by the Stage A multi-tenant catalog migration
    // (2026_05_10_000001_add_school_id_to_pas_catalog_tables.php).
    $indexes = collect(Schema::getIndexes('pas_allowances'));
    expect($indexes->contains(fn ($idx) => $idx['columns'] === ['code'] && $idx['unique']))->toBeFalse();
    expect($indexes->contains(fn ($idx) => $idx['columns'] === ['school_id', 'code'] && $idx['unique']))->toBeTrue();

    // Single-column index on is_active
    expect($indexes->contains(fn ($idx) => $idx['name'] === 'pas_allowances_is_active_idx'))->toBeTrue();
});

it('creates pas_deduction_types with the expected columns and indexes', function (): void {
    expect(Schema::hasTable('pas_deduction_types'))->toBeTrue();

    expect(Schema::hasColumns('pas_deduction_types', [
        'id', 'code', 'name', 'is_taxable', 'calc_method', 'source',
        'is_active', 'notes', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::getColumnType('pas_deduction_types', 'id'))->toBe('integer');
    expect(Schema::getColumnType('pas_deduction_types', 'code'))->toBe('varchar');
    expect(Schema::getColumnType('pas_deduction_types', 'name'))->toBe('varchar');
    expect(Schema::getColumnType('pas_deduction_types', 'is_taxable'))->toBe('tinyint');
    expect(Schema::getColumnType('pas_deduction_types', 'calc_method'))->toBe('varchar');
    expect(Schema::getColumnType('pas_deduction_types', 'source'))->toBe('varchar');
    expect(Schema::getColumnType('pas_deduction_types', 'is_active'))->toBe('tinyint');

    // Unique on `code` was converted to a composite (school_id, code) unique
    // by the Stage A multi-tenant catalog migration
    // (2026_05_10_000001_add_school_id_to_pas_catalog_tables.php).
    $indexes = collect(Schema::getIndexes('pas_deduction_types'));
    expect($indexes->contains(fn ($idx) => $idx['columns'] === ['code'] && $idx['unique']))->toBeFalse();
    expect($indexes->contains(fn ($idx) => $idx['columns'] === ['school_id', 'code'] && $idx['unique']))->toBeTrue();
    expect($indexes->contains(fn ($idx) => $idx['name'] === 'pas_deduction_types_is_active_idx'))->toBeTrue();
});

it('creates pas_employee_allowances with FKs and the composite index', function (): void {
    expect(Schema::hasTable('pas_employee_allowances'))->toBeTrue();

    expect(Schema::hasColumns('pas_employee_allowances', [
        'id', 'employee_profile_id', 'allowance_id', 'amount_centavos',
        'schedule', 'effective_from', 'effective_to', 'notes',
        'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::getColumnType('pas_employee_allowances', 'employee_profile_id'))->toBe('integer');
    expect(Schema::getColumnType('pas_employee_allowances', 'allowance_id'))->toBe('integer');
    // SQLite reports bigint columns as `integer`; MySQL stores `bigint`.
    expect(Schema::getColumnType('pas_employee_allowances', 'amount_centavos'))->toBe('integer');
    expect(Schema::getColumnType('pas_employee_allowances', 'schedule'))->toBe('varchar');
    expect(Schema::getColumnType('pas_employee_allowances', 'effective_from'))->toBe('date');
    expect(Schema::getColumnType('pas_employee_allowances', 'effective_to'))->toBe('date');

    // Composite index on (employee_profile_id, effective_from)
    $indexes = collect(Schema::getIndexes('pas_employee_allowances'));
    expect($indexes->contains(
        fn ($idx) => $idx['name'] === 'pas_employee_allowances_profile_effective_idx'
            && $idx['columns'] === ['employee_profile_id', 'effective_from'],
    ))->toBeTrue();

    // Foreign keys
    $foreignKeys = collect(Schema::getForeignKeys('pas_employee_allowances'));
    expect($foreignKeys->contains(
        fn ($fk) => $fk['columns'] === ['employee_profile_id']
            && $fk['foreign_table'] === 'pas_employee_profiles'
            && $fk['on_delete'] === 'cascade',
    ))->toBeTrue();
    expect($foreignKeys->contains(
        fn ($fk) => $fk['columns'] === ['allowance_id']
            && $fk['foreign_table'] === 'pas_allowances'
            && $fk['on_delete'] === 'restrict',
    ))->toBeTrue();
});

it('creates pas_employee_deductions with FKs, percent column, and composite index', function (): void {
    expect(Schema::hasTable('pas_employee_deductions'))->toBeTrue();

    expect(Schema::hasColumns('pas_employee_deductions', [
        'id', 'employee_profile_id', 'deduction_type_id', 'amount_centavos',
        'percent_basis_points', 'schedule', 'effective_from', 'effective_to',
        'notes', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::getColumnType('pas_employee_deductions', 'employee_profile_id'))->toBe('integer');
    expect(Schema::getColumnType('pas_employee_deductions', 'deduction_type_id'))->toBe('integer');
    // SQLite reports bigint and int columns identically as `integer`.
    expect(Schema::getColumnType('pas_employee_deductions', 'amount_centavos'))->toBe('integer');
    expect(Schema::getColumnType('pas_employee_deductions', 'percent_basis_points'))->toBe('integer');
    expect(Schema::getColumnType('pas_employee_deductions', 'schedule'))->toBe('varchar');
    expect(Schema::getColumnType('pas_employee_deductions', 'effective_from'))->toBe('date');

    // Composite index on (employee_profile_id, effective_from)
    $indexes = collect(Schema::getIndexes('pas_employee_deductions'));
    expect($indexes->contains(
        fn ($idx) => $idx['name'] === 'pas_employee_deductions_profile_effective_idx'
            && $idx['columns'] === ['employee_profile_id', 'effective_from'],
    ))->toBeTrue();

    // Foreign keys
    $foreignKeys = collect(Schema::getForeignKeys('pas_employee_deductions'));
    expect($foreignKeys->contains(
        fn ($fk) => $fk['columns'] === ['employee_profile_id']
            && $fk['foreign_table'] === 'pas_employee_profiles'
            && $fk['on_delete'] === 'cascade',
    ))->toBeTrue();
    expect($foreignKeys->contains(
        fn ($fk) => $fk['columns'] === ['deduction_type_id']
            && $fk['foreign_table'] === 'pas_deduction_types'
            && $fk['on_delete'] === 'restrict',
    ))->toBeTrue();
});

it('creates pas_employee_loans with balance, lock_version, and composite index', function (): void {
    expect(Schema::hasTable('pas_employee_loans'))->toBeTrue();

    expect(Schema::hasColumns('pas_employee_loans', [
        'id', 'employee_profile_id', 'code', 'principal_centavos',
        'outstanding_balance_centavos', 'monthly_amortization_centavos',
        'schedule', 'started_on', 'closed_on', 'lock_version', 'notes',
        'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::getColumnType('pas_employee_loans', 'employee_profile_id'))->toBe('integer');
    expect(Schema::getColumnType('pas_employee_loans', 'code'))->toBe('varchar');
    // SQLite reports bigint and int columns identically as `integer`.
    expect(Schema::getColumnType('pas_employee_loans', 'principal_centavos'))->toBe('integer');
    expect(Schema::getColumnType('pas_employee_loans', 'outstanding_balance_centavos'))->toBe('integer');
    expect(Schema::getColumnType('pas_employee_loans', 'monthly_amortization_centavos'))->toBe('integer');
    expect(Schema::getColumnType('pas_employee_loans', 'schedule'))->toBe('varchar');
    expect(Schema::getColumnType('pas_employee_loans', 'started_on'))->toBe('date');
    expect(Schema::getColumnType('pas_employee_loans', 'closed_on'))->toBe('date');
    expect(Schema::getColumnType('pas_employee_loans', 'lock_version'))->toBe('integer');

    // Composite index on (employee_profile_id, closed_on)
    $indexes = collect(Schema::getIndexes('pas_employee_loans'));
    expect($indexes->contains(
        fn ($idx) => $idx['name'] === 'pas_employee_loans_profile_closed_idx'
            && $idx['columns'] === ['employee_profile_id', 'closed_on'],
    ))->toBeTrue();

    // Foreign key on employee_profile_id with cascade delete
    $foreignKeys = collect(Schema::getForeignKeys('pas_employee_loans'));
    expect($foreignKeys->contains(
        fn ($fk) => $fk['columns'] === ['employee_profile_id']
            && $fk['foreign_table'] === 'pas_employee_profiles'
            && $fk['on_delete'] === 'cascade',
    ))->toBeTrue();
});

it('creates pas_payroll_adjustments with kind, applies_on, and the deferred period FK column', function (): void {
    expect(Schema::hasTable('pas_payroll_adjustments'))->toBeTrue();

    expect(Schema::hasColumns('pas_payroll_adjustments', [
        'id', 'employee_profile_id', 'kind', 'is_taxable', 'amount_centavos',
        'applies_on', 'label', 'reason', 'applied_pay_period_id',
        'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::getColumnType('pas_payroll_adjustments', 'employee_profile_id'))->toBe('integer');
    expect(Schema::getColumnType('pas_payroll_adjustments', 'kind'))->toBe('varchar');
    expect(Schema::getColumnType('pas_payroll_adjustments', 'is_taxable'))->toBe('tinyint');
    // SQLite reports bigint columns as `integer`; MySQL stores `bigint`.
    expect(Schema::getColumnType('pas_payroll_adjustments', 'amount_centavos'))->toBe('integer');
    expect(Schema::getColumnType('pas_payroll_adjustments', 'applies_on'))->toBe('date');
    expect(Schema::getColumnType('pas_payroll_adjustments', 'label'))->toBe('varchar');
    expect(Schema::getColumnType('pas_payroll_adjustments', 'reason'))->toBe('text');
    expect(Schema::getColumnType('pas_payroll_adjustments', 'applied_pay_period_id'))->toBe('integer');

    // applied_pay_period_id has NO foreign key — pas_pay_periods does not exist until Week 9
    $foreignKeys = collect(Schema::getForeignKeys('pas_payroll_adjustments'));
    expect($foreignKeys->contains(fn ($fk) => $fk['columns'] === ['applied_pay_period_id']))->toBeFalse();

    // Composite index on (employee_profile_id, applies_on)
    $indexes = collect(Schema::getIndexes('pas_payroll_adjustments'));
    expect($indexes->contains(
        fn ($idx) => $idx['name'] === 'pas_payroll_adjustments_profile_applies_on_idx'
            && $idx['columns'] === ['employee_profile_id', 'applies_on'],
    ))->toBeTrue();

    // Cascade FK on employee_profile_id
    expect($foreignKeys->contains(
        fn ($fk) => $fk['columns'] === ['employee_profile_id']
            && $fk['foreign_table'] === 'pas_employee_profiles'
            && $fk['on_delete'] === 'cascade',
    ))->toBeTrue();
});

it('inserts and retrieves a row in every Week 7 table without errors', function (): void {
    // Smoke: the schema accepts a minimal write through Eloquent factories.
    // This catches column-type / nullability mismatches the column-type
    // assertions above would not detect (e.g. NOT NULL on a column the
    // factory omits).

    $deduction = DeductionType::factory()->create();
    $allowance = Allowance::factory()->create();
    $profile = EmployeeProfile::factory()->create();

    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
        'deduction_type_id' => $deduction->id,
    ]);

    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
        'allowance_id' => $allowance->id,
    ]);

    EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    expect(DB::table('pas_deduction_types')->count())->toBe(1);
    expect(DB::table('pas_allowances')->count())->toBe(1);
    expect(DB::table('pas_employee_deductions')->count())->toBe(1);
    expect(DB::table('pas_employee_allowances')->count())->toBe(1);
    expect(DB::table('pas_employee_loans')->count())->toBe(1);
    expect(DB::table('pas_payroll_adjustments')->count())->toBe(1);
});
