<?php

declare(strict_types=1);

use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\School;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Phase D.4 schema gate.
 *
 * Pins the post-D.4 state of the tenant-scoped pas_* tables that are
 * exclusively written via Eloquent (and therefore exercise BelongsToTenant
 * on every insert) — every `school_id` column listed here is NOT NULL, so
 * any insert that omits it (or sets it to NULL) fails at the database
 * layer. The schema-shape assertion runs only on MySQL (sqlite test env
 * carries the column as nullable per the migration's driver split, but
 * BelongsToTenant + the migration's MySQL-side ALTER keep the production
 * invariant honest).
 *
 * Exemptions (school_id is intentionally NULLABLE for these tables, so
 * they are NOT listed below):
 *
 *   - pas_jobs / pas_failed_jobs / pas_notifications / pas_password_resets
 *     / pas_job_batches — Laravel's queue / notification / Fortify systems
 *     insert via raw query builder, bypassing the trait. Relaxed by
 *     2026_05_09_000001_relax_school_id_on_framework_tables.
 *
 *   - pas_audit_logs — AuditObserver fires for every auditable model,
 *     including writes to GLOBAL catalogs that deliberately do NOT use
 *     BelongsToTenant (e.g., StatutoryContribution — PH BIR/SSS/PhilHealth
 *     /Pag-IBIG rates are national law, not per-school config). Seeders,
 *     platform-admin edits, and console contexts legitimately produce
 *     audit rows with a null school_id. Relaxed by
 *     2026_05_11_000001_relax_school_id_on_pas_audit_logs.
 *
 * Pair with PasSchoolIdColumnTest, which pins the D.1 shape (column exists,
 * has FK + index, composite uniques in place).
 */

/** @var list<string> */
$tenantScopedTables = [
    'pas_employee_profiles',
    'pas_pay_periods',
    'pas_payroll_runs',
    'pas_payslips',
    'pas_employee_allowances',
    'pas_employee_deductions',
    'pas_employee_loans',
    'pas_payroll_adjustments',
    'pas_accounting_periods',
    'pas_jobs',
    'pas_failed_jobs',
    'pas_notifications',
    'pas_password_resets',
    'pas_job_batches',
];

it('declares school_id NOT NULL on every tenant-scoped pas_* table', function () use ($tenantScopedTables): void {
    // NOT NULL nullability assertion is MySQL-only — D.4 ALTER is skipped on
    // sqlite (the test in-memory DB). The migration file's driver split is
    // the source of truth on sqlite.
    if (DB::connection()->getDriverName() !== 'mysql') {
        expect(true)->toBeTrue();

        return;
    }

    $database = DB::getDatabaseName();

    foreach ($tenantScopedTables as $table) {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, 'school_id'],
        );

        expect($row)->not->toBeNull("Expected {$table}.school_id to exist.");
        expect($row->IS_NULLABLE)->toBe(
            'NO',
            "Expected {$table}.school_id to be NOT NULL post-D.4, got IS_NULLABLE={$row->IS_NULLABLE}.",
        );
    }
});

it('rejects inserting a row with NULL school_id', function (): void {
    // NOT NULL constraint enforcement is MySQL-only — sqlite column stays
    // nullable post-D.4 because sqlite cannot ALTER a column's nullability
    // without a full table rebuild.
    if (DB::connection()->getDriverName() !== 'mysql') {
        expect(true)->toBeTrue();

        return;
    }

    // Build a valid EmployeeProfile row payload from the factory and force
    // school_id to NULL. The MySQL NOT NULL constraint must reject the
    // insert at the database layer (SQLSTATE 23000).
    $payload = EmployeeProfile::factory()->raw([
        'school_id' => null,
    ]);
    // raw() includes the school_id key; ensure it's actually null on the
    // payload that hits the driver.
    $payload['school_id'] = null;
    $payload['created_at'] = now();
    $payload['updated_at'] = now();
    // exempted_contribution_codes is JSON-cast on the model — DB::insert
    // bypasses the cast, so encode here.
    if (isset($payload['exempted_contribution_codes']) && is_array($payload['exempted_contribution_codes'])) {
        $payload['exempted_contribution_codes'] = json_encode($payload['exempted_contribution_codes']);
    }

    expect(fn () => DB::table('pas_employee_profiles')->insert($payload))
        ->toThrow(QueryException::class);
});

it('keeps every existing tenant-scoped row tagged with a school_id', function () use ($tenantScopedTables): void {
    // Defense-in-depth — every row that survived the migration must have a
    // non-null school_id (otherwise the ALTER would have failed). This test
    // catches the regression where a code path bypasses BelongsToTenant and
    // inserts with NULL on sqlite (where the column is still nullable).
    foreach ($tenantScopedTables as $table) {
        $nullCount = DB::table($table)->whereNull('school_id')->count();
        expect($nullCount)->toBe(
            0,
            "Expected zero null school_id rows in {$table}, found {$nullCount}.",
        );
    }

    // Ensure the default school still exists — the seeder ran in beforeEach.
    expect(School::query()->where('slug', 'default')->exists())->toBeTrue();
});
