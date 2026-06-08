<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase B.1 schema gate.
 *
 * Pins the shape of `pas_schools` — the central tenant registry that
 * Phase C will read per request to rebind the `lms` connection. Tests
 * run against the in-memory SQLite connection configured in phpunit.xml;
 * SQLite collapses bigint -> integer affinity, so column-type assertions
 * reflect that. The MySQL types are the source of truth in the
 * migration file; the explicit MySQL-only check below verifies the
 * `text` type for `lms_db_password` against information_schema when
 * running on MySQL.
 */

it('creates pas_schools with the expected columns', function (): void {
    expect(Schema::hasTable('pas_schools'))->toBeTrue();

    expect(Schema::hasColumns('pas_schools', [
        'id',
        'name',
        'slug',
        'domain',
        'lms_db_host',
        'lms_db_port',
        'lms_db_database',
        'lms_db_username',
        'lms_db_password',
        'lms_db_charset',
        'is_active',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    expect(Schema::getColumnType('pas_schools', 'id'))->toBe('integer');
    expect(Schema::getColumnType('pas_schools', 'name'))->toBe('varchar');
    expect(Schema::getColumnType('pas_schools', 'slug'))->toBe('varchar');
    expect(Schema::getColumnType('pas_schools', 'lms_db_host'))->toBe('varchar');
    expect(Schema::getColumnType('pas_schools', 'lms_db_username'))->toBe('varchar');
    expect(Schema::getColumnType('pas_schools', 'lms_db_charset'))->toBe('varchar');
});

it('places a unique index on slug', function (): void {
    $indexes = collect(Schema::getIndexes('pas_schools'));

    expect($indexes->contains(
        fn ($idx) => $idx['columns'] === ['slug'] && $idx['unique'],
    ))->toBeTrue();
});

it('places a unique index on domain', function (): void {
    $indexes = collect(Schema::getIndexes('pas_schools'));

    expect($indexes->contains(
        fn ($idx) => $idx['columns'] === ['domain'] && $idx['unique'],
    ))->toBeTrue();
});

it('places a non-unique index on is_active', function (): void {
    $indexes = collect(Schema::getIndexes('pas_schools'));

    expect($indexes->contains(
        fn ($idx) => $idx['name'] === 'pas_schools_is_active_idx'
            && $idx['columns'] === ['is_active']
            && ! $idx['unique'],
    ))->toBeTrue();
});

it('stores lms_db_password as a text-typed column on MySQL', function (): void {
    // SQLite collapses TEXT/VARCHAR affinity, so this assertion is only
    // meaningful on MySQL. The migration file is the source of truth on
    // SQLite — test environments run sqlite by default.
    $driver = DB::connection()->getDriverName();

    if ($driver === 'mysql') {
        $row = DB::selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() '
            .'AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['pas_schools', 'lms_db_password'],
        );

        expect($row)->not->toBeNull();
        expect(strtolower((string) $row->DATA_TYPE))->toBe('text');

        return;
    }

    // SQLite path: assert via Laravel's column-type API. SQLite's TEXT
    // affinity reports as `text` here as well.
    expect(Schema::getColumnType('pas_schools', 'lms_db_password'))->toBe('text');
});

it('allows nullable domain so default-school rows can elide a subdomain', function (): void {
    $rowsBefore = DB::table('pas_schools')->count();

    DB::table('pas_schools')->insert([
        'name' => 'No-Domain School',
        'slug' => 'no-domain-school',
        'domain' => null,
        'lms_db_host' => '127.0.0.1',
        'lms_db_port' => 3306,
        'lms_db_database' => 'lms_x',
        'lms_db_username' => 'root',
        'lms_db_password' => 'placeholder',
        'lms_db_charset' => 'utf8mb4',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('pas_schools')->count())->toBe($rowsBefore + 1);
});
