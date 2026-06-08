<?php

declare(strict_types=1);

use App\Models\Lms\User as LmsUser;
use App\Models\Pas\School;
use App\Services\Auth\UpsertPasUserFromLms;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Phase D.3 — cross-LMS collision safety on pas_users.
 *
 * Composite unique on (school_id, lms_user_id) keeps two distinct LMS
 * deployments' user-id-5 rows distinct in the central pas_users table.
 *
 * Cases:
 *  1. The composite unique allows two LMSes to share the same internal id.
 *  2. The composite unique still blocks duplicate (school_id, lms_user_id).
 *  3. Platform-admin rows with NULL lms_user_id can coexist (NULL-distinct).
 *  4. UpsertPasUserFromLms uses the composite key for lookup.
 *  5. The legacy email-based fallback still matches pre-D.3 rows.
 */

it('composite (school_id, lms_user_id) unique allows two LMSes with same internal id', function (): void {
    $schoolA = School::query()->where('slug', 'default')->firstOrFail();
    $schoolB = School::factory()->create(['slug' => 'collision-school-b']);

    DB::table('pas_users')->insert([
        'school_id' => $schoolA->getKey(),
        'lms_user_id' => 5,
        'name' => 'School A John',
        'email' => 'john@a.test',
        'password' => 'hashed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pas_users')->insert([
        'school_id' => $schoolB->getKey(),
        'lms_user_id' => 5,
        'name' => 'School B Jane',
        'email' => 'jane@b.test',
        'password' => 'hashed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('pas_users')->where('lms_user_id', 5)->count())->toBe(2);
    expect(DB::table('pas_users')->where('school_id', $schoolA->getKey())->where('lms_user_id', 5)->count())->toBe(1);
    expect(DB::table('pas_users')->where('school_id', $schoolB->getKey())->where('lms_user_id', 5)->count())->toBe(1);
});

it('same (school_id, lms_user_id) cannot exist twice', function (): void {
    $schoolA = School::query()->where('slug', 'default')->firstOrFail();

    DB::table('pas_users')->insert([
        'school_id' => $schoolA->getKey(),
        'lms_user_id' => 7,
        'name' => 'First John',
        'email' => 'first-john@a.test',
        'password' => 'hashed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('pas_users')->insert([
        'school_id' => $schoolA->getKey(),
        'lms_user_id' => 7,
        'name' => 'Second John',
        'email' => 'second-john@a.test',
        'password' => 'hashed',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Only the first row landed.
    expect(DB::table('pas_users')->where('lms_user_id', 7)->count())->toBe(1);
});

it('platform-admin rows with NULL lms_user_id can coexist', function (): void {
    // Both MySQL and SQLite treat NULL as distinct in composite uniques,
    // so multiple (NULL, NULL) rows pass the composite. The email unique
    // still enforces single-row-per-email.
    DB::table('pas_users')->insert([
        'school_id' => null,
        'lms_user_id' => null,
        'name' => 'Platform Admin One',
        'email' => 'platform-one@test.local',
        'password' => 'hashed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pas_users')->insert([
        'school_id' => null,
        'lms_user_id' => null,
        'name' => 'Platform Admin Two',
        'email' => 'platform-two@test.local',
        'password' => 'hashed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(
        DB::table('pas_users')
            ->whereNull('school_id')
            ->whereNull('lms_user_id')
            ->count()
    )->toBe(2);
});

it('UpsertPasUserFromLms uses composite key for lookup', function (): void {
    $schoolA = School::query()->where('slug', 'default')->firstOrFail();
    $schoolB = School::factory()->create(['slug' => 'collision-upsert-school-b']);

    // Pre-seed: school A already has a payroll mirror for LMS user id=5.
    $preExistingId = DB::table('pas_users')->insertGetId([
        'school_id' => $schoolA->getKey(),
        'lms_user_id' => 5,
        'name' => 'Pre-existing John',
        'email' => 'pre-existing-john@a.test',
        'password' => 'pre-existing-hash',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(UpsertPasUserFromLms::class);

    // Build an in-memory LmsUser representing the school-A login. The
    // ReadOnlyModel base blocks save/update/delete/creating events — but
    // construction and attribute reads are fine, which is all the service
    // does.
    $lmsUserA = new LmsUser([
        'full_name' => 'Updated John',
        'email' => 'updated-john@a.test',
        'password' => 'fresh-hash-a',
    ]);
    $lmsUserA->id = 5;

    // School A is current — service should UPDATE the pre-existing row.
    $schoolA->makeCurrent();
    $rowsBefore = DB::table('pas_users')->count();
    $resultA = $service($lmsUserA);
    $rowsAfter = DB::table('pas_users')->count();

    expect($rowsAfter)->toBe($rowsBefore); // No new row
    expect($resultA->id)->toBe($preExistingId);
    expect((int) $resultA->school_id)->toBe($schoolA->getKey());
    expect((int) $resultA->lms_user_id)->toBe(5);
    expect((string) $resultA->name)->toBe('Updated John');

    // Now switch to school B and login an LMS user also at id=5 — should
    // CREATE a new pas_users row, not collide with school A's.
    $schoolB->makeCurrent();

    $lmsUserB = new LmsUser([
        'full_name' => 'School B Jane',
        'email' => 'jane@b.test',
        'password' => 'fresh-hash-b',
    ]);
    $lmsUserB->id = 5;

    $resultB = $service($lmsUserB);

    expect($resultB->id)->not->toBe($preExistingId);
    expect((int) $resultB->school_id)->toBe($schoolB->getKey());
    expect((int) $resultB->lms_user_id)->toBe(5);
    expect((string) $resultB->name)->toBe('School B Jane');

    // Two rows now exist for lms_user_id=5, one per school.
    expect(DB::table('pas_users')->where('lms_user_id', 5)->count())->toBe(2);
});

it('legacy email-based fallback still works', function (): void {
    $schoolA = School::query()->where('slug', 'default')->firstOrFail();

    // Pre-D.3 row: lms_user_id set, school_id NULL. Pretend the upsert
    // ran before the auto-fill on login was wired up.
    $legacyId = DB::table('pas_users')->insertGetId([
        'school_id' => null,
        'lms_user_id' => 5,
        'name' => 'Legacy User',
        'email' => 'legacy@test',
        'password' => 'legacy-hash',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(UpsertPasUserFromLms::class);

    $lmsUser = new LmsUser([
        'full_name' => 'Legacy User Refreshed',
        'email' => 'legacy@test',
        'password' => 'fresh-hash',
    ]);
    $lmsUser->id = 5;

    // School A is current. Composite-key lookup misses (school_id is NULL
    // on the legacy row). Email fallback hits.
    $schoolA->makeCurrent();
    $rowsBefore = DB::table('pas_users')->count();
    $result = $service($lmsUser);
    $rowsAfter = DB::table('pas_users')->count();

    expect($rowsAfter)->toBe($rowsBefore); // No new row — matched by email.
    expect($result->id)->toBe($legacyId);
    // Both school_id and lms_user_id are refreshed onto the legacy row.
    expect((int) $result->school_id)->toBe($schoolA->getKey());
    expect((int) $result->lms_user_id)->toBe(5);
    expect((string) $result->name)->toBe('Legacy User Refreshed');
});
