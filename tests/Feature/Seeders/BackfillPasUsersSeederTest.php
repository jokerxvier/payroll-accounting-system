<?php

declare(strict_types=1);

use App\Models\Lms\User as LmsUser;
use Database\Seeders\BackfillPasUsersSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * BackfillPasUsersSeeder coverage.
 *
 * Locks in the Phase A.1 contract:
 *   - the seeder copies every LMS user into `pas_users` preserving ids,
 *   - it is idempotent (re-running keeps the same row ids and does not
 *     duplicate),
 *   - it degrades gracefully when the LMS read fails (logs + reports +
 *     no exception bubbles out).
 *
 * The default test connection is sqlite in-memory (phpunit.xml), so
 * `pas_users` is a fresh empty sqlite table at the start of every test.
 * The `lms` connection is configured separately in config/database.php
 * and points at the live MySQL `payroll_db`. We READ from it; writes are
 * blocked by ReadOnlyModel and verified by LmsReadOnlyTest.
 */

it('backfills pas_users from LMS users preserving ids', function (): void {
    $lmsCount = LmsUser::query()->count();

    expect($lmsCount)->toBeGreaterThanOrEqual(20); // Same floor as LmsReadOnlyTest.

    $this->seed(BackfillPasUsersSeeder::class);

    expect(DB::table('pas_users')->count())->toBe($lmsCount);

    // Spot-check the first three rows: id is preserved, email matches,
    // name maps from LMS `full_name`, and lms_user_id cross-references back.
    $sample = LmsUser::query()->orderBy('id')->limit(3)->get();

    foreach ($sample as $lmsUser) {
        $pas = DB::table('pas_users')->where('id', $lmsUser->id)->first();

        expect($pas)->not->toBeNull();
        expect((int) $pas->id)->toBe((int) $lmsUser->id);
        expect((int) $pas->lms_user_id)->toBe((int) $lmsUser->id);
        expect((string) $pas->email)->toBe((string) $lmsUser->email);
        expect((string) $pas->name)->toBe((string) $lmsUser->full_name);
    }
});

it('leaves school_id null because pas_schools is a Phase B concern', function (): void {
    $this->seed(BackfillPasUsersSeeder::class);

    $rowsWithSchoolId = DB::table('pas_users')->whereNotNull('school_id')->count();

    expect($rowsWithSchoolId)->toBe(0);
});

it('leaves Fortify two-factor + email_verified_at columns null because LMS users does not carry them', function (): void {
    $this->seed(BackfillPasUsersSeeder::class);

    expect(DB::table('pas_users')->whereNotNull('two_factor_secret')->count())->toBe(0);
    expect(DB::table('pas_users')->whereNotNull('two_factor_recovery_codes')->count())->toBe(0);
    expect(DB::table('pas_users')->whereNotNull('two_factor_confirmed_at')->count())->toBe(0);
    expect(DB::table('pas_users')->whereNotNull('email_verified_at')->count())->toBe(0);
});

it('is idempotent on re-run', function (): void {
    $this->seed(BackfillPasUsersSeeder::class);

    $idsAfterFirst = DB::table('pas_users')->orderBy('id')->pluck('id')->all();
    $countAfterFirst = count($idsAfterFirst);

    expect($countAfterFirst)->toBeGreaterThan(0);

    $this->seed(BackfillPasUsersSeeder::class);

    $idsAfterSecond = DB::table('pas_users')->orderBy('id')->pluck('id')->all();

    expect(count($idsAfterSecond))->toBe($countAfterFirst);
    expect($idsAfterSecond)->toBe($idsAfterFirst);
});

it('logs and exits gracefully when the LMS read fails', function (): void {
    Log::spy();

    // Repoint the lms connection at a non-existent MySQL host so any
    // query through it fails fast. The seeder must catch the throwable,
    // call report(), and return without bubbling an exception.
    config([
        'database.connections.lms' => array_merge(
            config('database.connections.lms'),
            [
                'host' => '127.0.0.1',
                'port' => '1', // No MySQL listens here.
                'database' => 'definitely_not_a_real_db_'.uniqid(),
                'username' => 'no_such_user',
                'password' => 'no_such_password',
            ],
        ),
    ]);

    DB::purge('lms');

    expect(fn () => $this->seed(BackfillPasUsersSeeder::class))->not->toThrow(Throwable::class);

    // The seeder must not have written anything when the read failed.
    expect(DB::table('pas_users')->count())->toBe(0);
});
