<?php

declare(strict_types=1);

use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase A.3 schema gate.
 *
 * Pins the post-A.3 state of the five FK constraints that originally
 * referenced the LMS-owned `users.id` and now must reference the payroll-DB
 * -owned `pas_users.id`:
 *
 *   pas_statutory_contributions.voided_by_user_id  → pas_users(id)
 *   pas_payroll_runs.approved_by_user_id           → pas_users(id)
 *   pas_payroll_runs.voided_by_user_id             → pas_users(id)
 *   pas_payroll_runs.submitted_by_user_id          → pas_users(id)
 *   pas_payroll_runs.posted_by_user_id             → pas_users(id)
 *
 * Tests rely on `Schema::getForeignKeys()` which Laravel implements
 * portably across MySQL and sqlite, so the same assertions hold whether
 * we're running against the in-memory sqlite test DB or against MySQL via
 * `--env`.
 */

/** @var list<array{table: string, column: string}> */
$redirectedFks = [
    ['table' => 'pas_statutory_contributions', 'column' => 'voided_by_user_id'],
    ['table' => 'pas_payroll_runs', 'column' => 'approved_by_user_id'],
    ['table' => 'pas_payroll_runs', 'column' => 'voided_by_user_id'],
    ['table' => 'pas_payroll_runs', 'column' => 'submitted_by_user_id'],
    ['table' => 'pas_payroll_runs', 'column' => 'posted_by_user_id'],
];

it('redirects every FK from users to pas_users', function () use ($redirectedFks): void {
    foreach ($redirectedFks as $fk) {
        $foreignKeys = collect(Schema::getForeignKeys($fk['table']));

        $match = $foreignKeys->first(
            fn (array $row): bool => $row['columns'] === [$fk['column']],
        );

        expect($match)->not->toBeNull(
            "Expected a foreign key on {$fk['table']}.{$fk['column']}, none found.",
        );
        expect($match['foreign_table'])->toBe(
            'pas_users',
            "Expected {$fk['table']}.{$fk['column']} → pas_users, got {$match['foreign_table']}.",
        );
        expect($match['foreign_columns'])->toBe(['id']);
    }
});

it('preserves nullOnDelete semantics on every redirected FK', function () use ($redirectedFks): void {
    foreach ($redirectedFks as $fk) {
        $foreignKeys = collect(Schema::getForeignKeys($fk['table']));

        $match = $foreignKeys->first(
            fn (array $row): bool => $row['columns'] === [$fk['column']],
        );

        expect($match)->not->toBeNull();
        expect($match['on_delete'])->toBe(
            'set null',
            "Expected {$fk['table']}.{$fk['column']} on_delete=set null, got {$match['on_delete']}.",
        );
    }
});

it('does not leave any pas_* FK pointing at the LMS users table', function (): void {
    // Sweep every pas_* table the migrations created and verify none of its
    // foreign keys still reference `users`. This catches regressions where a
    // future migration accidentally re-adds a `users` FK.
    $sweepTables = [
        'pas_statutory_contributions',
        'pas_payroll_runs',
        'pas_payslips',
        'pas_pay_periods',
        'pas_employee_profiles',
        'pas_employee_allowances',
        'pas_employee_deductions',
        'pas_employee_loans',
        'pas_payroll_adjustments',
        'pas_audit_logs',
        'pas_allowances',
        'pas_deduction_types',
        'pas_users',
    ];

    foreach ($sweepTables as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }

        foreach (Schema::getForeignKeys($table) as $fk) {
            expect($fk['foreign_table'])->not->toBe(
                'users',
                "Found a stray FK on {$table} referencing users — Phase A.3 is incomplete.",
            );
        }
    }
});

it('cascades pas_users deletion as a SET NULL on linked payroll runs', function (): void {
    // End-to-end smoke: create a pas_users row, link a pas_payroll_runs row
    // via submitted_by_user_id, delete the pas_users row, assert the FK
    // column on the run is set to NULL (not a constraint violation, not a
    // deleted run). Validates both the FK target and the on-delete rule
    // through actual data, not just metadata.

    if (DB::connection()->getDriverName() === 'sqlite') {
        // sqlite enforces FKs only when `PRAGMA foreign_keys = ON` — Laravel
        // sets this on connection bootstrap. Make explicit just in case.
        DB::statement('PRAGMA foreign_keys = ON');
    }

    // Insert via the Eloquent factories where available so the test stays
    // resilient to incidental schema/column changes; the pas_users row is
    // raw because UserFactory mirrors into the testing `users` table and
    // we want to keep this test focused purely on the FK behavior.
    $userId = DB::table('pas_users')->insertGetId([
        'lms_user_id' => null,
        'school_id' => null,
        'name' => 'Phase A.3 cascade smoke',
        'email' => 'phase-a3-cascade@example.test',
        'email_verified_at' => null,
        'password' => 'hashed',
        'remember_token' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $period = PayPeriod::factory()->open()->create();
    $run = PayrollRun::factory()->create([
        'pay_period_id' => $period->id,
        'submitted_by_user_id' => $userId,
    ]);

    DB::table('pas_users')->where('id', $userId)->delete();

    $reloaded = DB::table('pas_payroll_runs')->where('id', $run->id)->first();

    expect($reloaded)->not->toBeNull('Run should NOT have been deleted by the cascade.');
    expect($reloaded->submitted_by_user_id)->toBeNull(
        'submitted_by_user_id should be NULL after the linked pas_users row was deleted.',
    );
});
