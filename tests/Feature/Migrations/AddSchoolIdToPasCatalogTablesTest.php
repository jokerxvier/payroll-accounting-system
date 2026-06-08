<?php

declare(strict_types=1);

use App\Models\Pas\School;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Stage A schema gate for the multi-tenant catalog conversion.
 *
 * Pins the post-Stage-A state of the two catalog tables. Both must have:
 *   - a nullable `school_id` column
 *   - an FK to pas_schools(id) with on_delete=restrict
 *   - a non-unique index on `school_id`
 *   - the legacy single-column `code` unique replaced by a composite
 *     `(school_id, code)` unique
 *
 * The cross-tenant clone behaviour is not asserted here — the migration runs
 * against the test DB (sqlite) before the SchoolSeeder fires, so the tables
 * have zero rows and the clone step is a no-op. The clone step is verified
 * via tinker against the dev DB; see the task report.
 *
 * Tests run against the in-memory SQLite connection per phpunit.xml.
 * Schema::getForeignKeys() and Schema::getIndexes() are Laravel's portable
 * wrappers and work on both sqlite and mysql, so the FK + index assertions
 * hold across both drivers.
 */

/** @var list<array{table: string, replace_unique: array<int,string>}> */
$catalogTables = [
    ['table' => 'pas_deduction_types', 'replace_unique' => ['code']],
    ['table' => 'pas_allowances', 'replace_unique' => ['code']],
];

it('adds a nullable school_id column to every catalog table', function () use ($catalogTables): void {
    foreach ($catalogTables as $config) {
        $table = $config['table'];

        expect(Schema::hasTable($table))->toBeTrue("Expected {$table} to exist.");
        expect(Schema::hasColumn($table, 'school_id'))->toBeTrue(
            "Expected {$table}.school_id to exist.",
        );

        $column = collect(Schema::getColumns($table))
            ->firstWhere('name', 'school_id');

        expect($column)->not->toBeNull("Expected column metadata for {$table}.school_id.");
        expect($column['nullable'])->toBeTrue("Expected {$table}.school_id to be nullable.");
    }
});

it('declares an FK from every school_id column to pas_schools(id)', function () use ($catalogTables): void {
    foreach ($catalogTables as $config) {
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

it('places a non-unique index on every school_id column', function () use ($catalogTables): void {
    foreach ($catalogTables as $config) {
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

it('replaces the code unique on pas_deduction_types with (school_id, code)', function (): void {
    $indexes = collect(Schema::getIndexes('pas_deduction_types'));

    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['code'] && $idx['unique'],
    ))->toBeFalse('Old single-column unique on code should have been dropped.');

    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['school_id', 'code'] && $idx['unique'],
    ))->toBeTrue('Expected composite unique on (school_id, code).');

    $schoolA = School::query()->where('slug', 'default')->firstOrFail();
    $schoolB = School::factory()->create();

    $base = [
        'name' => 'Stage A test row',
        'is_taxable' => true,
        'calc_method' => 'fixed',
        'source' => 'employee',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('pas_deduction_types')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'code' => 'STAGE_A_DED',
    ]));
    DB::table('pas_deduction_types')->insert(array_merge($base, [
        'school_id' => $schoolB->id,
        'code' => 'STAGE_A_DED',
    ]));

    expect(
        DB::table('pas_deduction_types')->where('code', 'STAGE_A_DED')->count(),
    )->toBe(2);

    expect(fn () => DB::table('pas_deduction_types')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'code' => 'STAGE_A_DED',
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('replaces the code unique on pas_allowances with (school_id, code)', function (): void {
    $indexes = collect(Schema::getIndexes('pas_allowances'));

    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['code'] && $idx['unique'],
    ))->toBeFalse('Old single-column unique on code should have been dropped.');

    expect($indexes->contains(
        fn (array $idx): bool => $idx['columns'] === ['school_id', 'code'] && $idx['unique'],
    ))->toBeTrue('Expected composite unique on (school_id, code).');

    $schoolA = School::query()->where('slug', 'default')->firstOrFail();
    $schoolB = School::factory()->create();

    $base = [
        'name' => 'Stage A test row',
        'is_taxable' => true,
        'is_de_minimis' => false,
        'de_minimis_cap_centavos' => null,
        'default_amount_centavos' => 100_000,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('pas_allowances')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'code' => 'STAGE_A_ALW',
    ]));
    DB::table('pas_allowances')->insert(array_merge($base, [
        'school_id' => $schoolB->id,
        'code' => 'STAGE_A_ALW',
    ]));

    expect(
        DB::table('pas_allowances')->where('code', 'STAGE_A_ALW')->count(),
    )->toBe(2);

    expect(fn () => DB::table('pas_allowances')->insert(array_merge($base, [
        'school_id' => $schoolA->id,
        'code' => 'STAGE_A_ALW',
    ])))->toThrow(UniqueConstraintViolationException::class);
});
