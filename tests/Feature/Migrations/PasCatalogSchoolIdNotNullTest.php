<?php

declare(strict_types=1);

use App\Models\Pas\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Stage E schema gate for the multi-tenant catalog conversion.
 *
 * Pins the post-Stage-E state of the two catalog tables (pas_deduction_types,
 * pas_allowances) — every `school_id` column is NOT NULL on MySQL, so any
 * insert that omits it (or sets it to NULL) fails at the database layer. The
 * schema-shape NOT NULL assertion runs only on MySQL (sqlite test env carries
 * the column as nullable per the migration's driver split, but
 * BelongsToTenant + the migration's MySQL-side ALTER keep the production
 * invariant honest).
 *
 * On sqlite (the in-memory test connection per phpunit.xml) we still assert:
 *   - the column exists on both catalog tables
 *   - no row in either catalog has a null school_id (would have aborted the
 *     Stage E ALTER on MySQL — defense-in-depth here)
 *
 * Pair with AddSchoolIdToPasCatalogTablesTest, which pins the Stage A shape
 * (column nullable, FK + index in place, composite uniques flipped).
 */

/** @var list<string> */
$catalogTables = [
    'pas_deduction_types',
    'pas_allowances',
];

it('keeps the school_id column on every catalog table', function () use ($catalogTables): void {
    foreach ($catalogTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected {$table} to exist.");
        expect(Schema::hasColumn($table, 'school_id'))->toBeTrue(
            "Expected {$table}.school_id to exist post-Stage-E.",
        );
    }
});

it('declares school_id NOT NULL on every catalog table', function () use ($catalogTables): void {
    // NOT NULL nullability assertion is MySQL-only — Stage E ALTER is skipped
    // on sqlite (the test in-memory DB). The migration file's driver split is
    // the source of truth on sqlite.
    if (DB::connection()->getDriverName() !== 'mysql') {
        expect(true)->toBeTrue();

        return;
    }

    $database = DB::getDatabaseName();

    foreach ($catalogTables as $table) {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, 'school_id'],
        );

        expect($row)->not->toBeNull("Expected {$table}.school_id to exist.");
        expect($row->IS_NULLABLE)->toBe(
            'NO',
            "Expected {$table}.school_id to be NOT NULL post-Stage-E, got IS_NULLABLE={$row->IS_NULLABLE}.",
        );
    }
});

it('keeps every catalog row tagged with a school_id', function () use ($catalogTables): void {
    // Defense-in-depth — every row that survived the migration must have a
    // non-null school_id (otherwise the ALTER would have failed on MySQL). On
    // sqlite the column is still nullable post-Stage-E, but no test fixture
    // should leave a NULL school_id behind because BelongsToTenant auto-fills
    // on creating.
    foreach ($catalogTables as $table) {
        $nullCount = DB::table($table)->whereNull('school_id')->count();
        expect($nullCount)->toBe(
            0,
            "Expected zero null school_id rows in {$table}, found {$nullCount}.",
        );
    }

    // Ensure the default school still exists — the seeder ran in beforeEach.
    expect(School::query()->where('slug', 'default')->exists())->toBeTrue();
});
