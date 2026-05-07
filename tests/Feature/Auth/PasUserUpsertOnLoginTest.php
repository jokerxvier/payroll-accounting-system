<?php

declare(strict_types=1);

use App\Models\Lms\User as LmsUser;
use App\Models\User;
use App\Services\Auth\UpsertPasUserFromLms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Phase A.2 — Fortify::authenticateUsing callback contract.
 *
 * The new auth flow:
 *   POST /login
 *   → look up LMS user by email (read-only `lms` connection)
 *   → Hash::check() against LMS password (LMS is identity master)
 *   → upsert pas_users id-preserved (UpsertPasUserFromLms)
 *   → return App\Models\User; Fortify logs the user in
 *
 * In tests the `lms` connection is overridden to point at the same sqlite
 * as the default connection (see tests/Pest.php). The testing migration
 * `0001_01_01_create_test_users_table.php` provides the LMS-shaped `users`
 * table. The factory's afterCreating hook keeps both tables in sync.
 *
 * To exercise the *first-login* path we must seed the LMS row directly
 * (not via the factory, which also pre-creates the pas_users row).
 */

/**
 * Inserts an LMS user row directly without creating the matching
 * pas_users row. Returns the LMS user id.
 */
function seedLmsOnlyUser(string $email, string $plainPassword, ?int $roleId = 4): int
{
    $hash = Hash::make($plainPassword);

    $id = (int) DB::table('users')->insertGetId([
        'full_name' => 'LMS-Only User',
        'email' => $email,
        'password' => $hash,
        'remember_token' => null,
        'email_verified_at' => null,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('creates pas_users on first successful login', function () {
    $email = 'first-login@example.test';
    $password = 'secret123';
    $lmsId = seedLmsOnlyUser($email, $password);

    expect(DB::table('pas_users')->where('id', $lmsId)->exists())->toBeFalse();

    $response = $this->post(route('login.store'), [
        'email' => $email,
        'password' => $password,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $row = DB::table('pas_users')->where('id', $lmsId)->first();

    expect($row)->not->toBeNull();
    expect((int) $row->id)->toBe($lmsId);
    expect((int) $row->lms_user_id)->toBe($lmsId);
    expect((string) $row->email)->toBe($email);
    expect((string) $row->name)->toBe('LMS-Only User');
    // Password is mirrored verbatim from the LMS row so subsequent Fortify
    // operations work without re-querying LMS.
    expect((string) $row->password)->toBe(LmsUser::query()->find($lmsId)->password);
});

it('updates pas_users on subsequent successful login when LMS password rotates', function () {
    $email = 'rotating-pwd@example.test';
    $oldPassword = 'old-password-123';
    $newPassword = 'new-password-456';
    $lmsId = seedLmsOnlyUser($email, $oldPassword);

    // First login provisions pas_users with the old hash.
    $this->post(route('login.store'), [
        'email' => $email,
        'password' => $oldPassword,
    ]);
    $this->assertAuthenticated();

    $oldPasHash = (string) DB::table('pas_users')->where('id', $lmsId)->value('password');

    // Simulate LMS-side password rotation (admin reset by some external
    // process). Logout to clear the session.
    $this->post(route('logout'));

    DB::table('users')
        ->where('id', $lmsId)
        ->update(['password' => Hash::make($newPassword)]);

    // Second login with the new password refreshes the pas_users mirror.
    $response = $this->post(route('login.store'), [
        'email' => $email,
        'password' => $newPassword,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $newPasHash = (string) DB::table('pas_users')->where('id', $lmsId)->value('password');

    expect($newPasHash)->not->toBe($oldPasHash);
    // Exactly one pas_users row exists for this id (no duplicates).
    expect(DB::table('pas_users')->where('id', $lmsId)->count())->toBe(1);
});

it('fails login on wrong password without creating pas_users', function () {
    $email = 'wrong-pwd@example.test';
    $lmsId = seedLmsOnlyUser($email, 'correct-password');

    $this->post(route('login.store'), [
        'email' => $email,
        'password' => 'incorrect-password',
    ]);

    $this->assertGuest();
    expect(DB::table('pas_users')->where('id', $lmsId)->exists())->toBeFalse();
});

it('fails login on missing email without creating pas_users', function () {
    $this->post(route('login.store'), [
        'email' => '',
        'password' => 'whatever',
    ]);

    $this->assertGuest();
    expect(DB::table('pas_users')->count())->toBe(0);
});

it('fails login on non-existent email without creating pas_users', function () {
    $this->post(route('login.store'), [
        'email' => 'nobody@example.test',
        'password' => 'whatever',
    ]);

    $this->assertGuest();
    expect(DB::table('pas_users')->count())->toBe(0);
});

it('upsert service is id-preserving and idempotent', function () {
    $email = 'upsert-direct@example.test';
    $lmsId = seedLmsOnlyUser($email, 'pwd123');

    $service = app(UpsertPasUserFromLms::class);
    $lmsUser = LmsUser::query()->find($lmsId);

    $first = $service($lmsUser);
    $second = $service($lmsUser);

    expect($first->id)->toBe($lmsId);
    expect($second->id)->toBe($lmsId);
    expect(DB::table('pas_users')->where('id', $lmsId)->count())->toBe(1);
});

/*
 * LMS-unreachable smoke. Forces the LmsUser query to throw by repointing
 * the lms connection at a non-existent host (mirrors the pattern in
 * BackfillPasUsersSeederTest). The Fortify callback must catch the
 * throwable, call report(), and return null so the request is treated as
 * "credentials don't match."
 */
it('fails login gracefully when the LMS read throws', function () {
    // The default lms→sqlite override from tests/Pest.php has been
    // applied; tear it down by re-pointing at a closed port and purging.
    config([
        'database.connections.lms' => array_merge(
            config('database.connections.lms'),
            [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '1', // No MySQL listens here.
                'database' => 'definitely_not_a_real_db_'.uniqid(),
                'username' => 'no_such_user',
                'password' => 'no_such_password',
            ],
        ),
    ]);

    DB::purge('lms');

    // Sanity check: pas_users still exists on the default connection
    // (the failure path must not corrupt it).
    expect(DB::table('pas_users')->count())->toBe(0);

    $this->post(route('login.store'), [
        'email' => 'anyone@example.test',
        'password' => 'whatever',
    ]);

    $this->assertGuest();
    // No pas_users row created — the LMS read failed before the upsert.
    expect(DB::table('pas_users')->count())->toBe(0);
});
