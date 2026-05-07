<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * Phase A.1 schema gate.
 *
 * Pins the shape of `pas_users` — the payroll-DB-owned auth table that
 * will, in subsequent slices (A.2 / A.3), become Fortify's home and
 * absorb the five FKs currently pointed at the LMS-owned `users.id`.
 *
 * Tests run against the in-memory SQLite connection configured in
 * phpunit.xml. SQLite collapses bigint → integer affinity (just like
 * Week7MigrationTest notes), so column-type assertions reflect that.
 * The MySQL types are the source of truth in the migration file.
 */

it('creates pas_users with the expected columns', function (): void {
    expect(Schema::hasTable('pas_users'))->toBeTrue();

    expect(Schema::hasColumns('pas_users', [
        'id',
        'lms_user_id',
        'school_id',
        'name',
        'email',
        'email_verified_at',
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    // SQLite reports bigint columns as `integer` — same convention as
    // Week7MigrationTest. The MySQL bigint guarantee comes from the
    // migration file itself.
    expect(Schema::getColumnType('pas_users', 'id'))->toBe('integer');
    expect(Schema::getColumnType('pas_users', 'lms_user_id'))->toBe('integer');
    expect(Schema::getColumnType('pas_users', 'school_id'))->toBe('integer');
    expect(Schema::getColumnType('pas_users', 'name'))->toBe('varchar');
    expect(Schema::getColumnType('pas_users', 'email'))->toBe('varchar');
    expect(Schema::getColumnType('pas_users', 'password'))->toBe('varchar');
    expect(Schema::getColumnType('pas_users', 'remember_token'))->toBe('varchar');
    expect(Schema::getColumnType('pas_users', 'two_factor_secret'))->toBe('text');
    expect(Schema::getColumnType('pas_users', 'two_factor_recovery_codes'))->toBe('text');
});

it('places a unique index on email', function (): void {
    $indexes = collect(Schema::getIndexes('pas_users'));

    expect($indexes->contains(
        fn ($idx) => $idx['columns'] === ['email'] && $idx['unique'],
    ))->toBeTrue();
});

it('places a non-unique index on lms_user_id', function (): void {
    $indexes = collect(Schema::getIndexes('pas_users'));

    expect($indexes->contains(
        fn ($idx) => $idx['name'] === 'pas_users_lms_user_id_idx'
            && $idx['columns'] === ['lms_user_id']
            && ! $idx['unique'],
    ))->toBeTrue();
});

it('places a non-unique index on school_id', function (): void {
    $indexes = collect(Schema::getIndexes('pas_users'));

    expect($indexes->contains(
        fn ($idx) => $idx['name'] === 'pas_users_school_id_idx'
            && $idx['columns'] === ['school_id']
            && ! $idx['unique'],
    ))->toBeTrue();
});

it('declares no foreign key constraints on lms_user_id or school_id', function (): void {
    // lms_user_id: LMS lives on a different connection — no FK possible.
    // school_id: pas_schools doesn't exist yet (Phase B) — no FK yet.
    $foreignKeys = collect(Schema::getForeignKeys('pas_users'));

    expect($foreignKeys->contains(fn ($fk) => $fk['columns'] === ['lms_user_id']))->toBeFalse();
    expect($foreignKeys->contains(fn ($fk) => $fk['columns'] === ['school_id']))->toBeFalse();
});

it('allows nullable lms_user_id, school_id, email_verified_at, two_factor_*', function (): void {
    // Smoke: insert a minimal row with all phase-A.1 nullables left null.
    $rowsBefore = DB::table('pas_users')->count();

    DB::table('pas_users')->insert([
        'lms_user_id' => null,
        'school_id' => null,
        'name' => 'Phase A.1 minimal user',
        'email' => 'phase-a1-min@example.test',
        'email_verified_at' => null,
        'password' => 'hashed',
        'remember_token' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('pas_users')->count())->toBe($rowsBefore + 1);
});
