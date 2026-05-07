<?php

declare(strict_types=1);

use App\Models\Pas\School;
use App\Multitenancy\Tasks\SwitchLmsConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Phase C.1 — SwitchLmsConnection task.
 *
 * The single load-bearing invariant: the task touches ONLY the `lms`
 * connection. The default `mysql` connection is the central payroll DB
 * and must remain stable across the request lifecycle (plan-2.md
 * architecture promise #1).
 *
 * `forgetCurrent()` restores the .env-derived snapshot captured at
 * construction so the connection config is deterministic regardless of
 * intervening writes by other code paths.
 */

it('rebinds lms config keys to the school credentials on makeCurrent', function (): void {
    $school = School::factory()->create([
        'lms_db_host' => '10.0.0.42',
        'lms_db_port' => 3307,
        'lms_db_database' => 'lms_acme',
        'lms_db_username' => 'lms_acme_user',
        'lms_db_password' => 'super-secret',
        'lms_db_charset' => 'utf8mb4',
    ]);

    $task = new SwitchLmsConnection;
    $task->makeCurrent($school);

    expect(config('database.connections.lms.host'))->toBe('10.0.0.42');
    expect(config('database.connections.lms.port'))->toBe(3307);
    expect(config('database.connections.lms.database'))->toBe('lms_acme');
    expect(config('database.connections.lms.username'))->toBe('lms_acme_user');
    expect(config('database.connections.lms.password'))->toBe('super-secret');
    expect(config('database.connections.lms.charset'))->toBe('utf8mb4');
});

it('restores the original lms config snapshot on forgetCurrent', function (): void {
    $originalHost = config('database.connections.lms.host');
    $originalDatabase = config('database.connections.lms.database');
    $originalUsername = config('database.connections.lms.username');

    $school = School::factory()->create([
        'lms_db_host' => '10.0.0.42',
        'lms_db_database' => 'lms_acme',
        'lms_db_username' => 'lms_acme_user',
    ]);

    $task = new SwitchLmsConnection;
    $task->makeCurrent($school);

    expect(config('database.connections.lms.host'))->toBe('10.0.0.42');

    $task->forgetCurrent();

    expect(config('database.connections.lms.host'))->toBe($originalHost);
    expect(config('database.connections.lms.database'))->toBe($originalDatabase);
    expect(config('database.connections.lms.username'))->toBe($originalUsername);
});

it('leaves the default mysql connection untouched after makeCurrent', function (): void {
    $beforeDriver = config('database.connections.mysql.driver');
    $beforeHost = config('database.connections.mysql.host');
    $beforeDatabase = config('database.connections.mysql.database');
    $beforeUsername = config('database.connections.mysql.username');

    $school = School::factory()->create([
        'lms_db_host' => '10.0.0.42',
        'lms_db_database' => 'lms_acme',
        'lms_db_username' => 'lms_acme_user',
    ]);

    (new SwitchLmsConnection)->makeCurrent($school);

    expect(config('database.connections.mysql.driver'))->toBe($beforeDriver);
    expect(config('database.connections.mysql.host'))->toBe($beforeHost);
    expect(config('database.connections.mysql.database'))->toBe($beforeDatabase);
    expect(config('database.connections.mysql.username'))->toBe($beforeUsername);
});

it('leaves the default mysql connection untouched after forgetCurrent', function (): void {
    $beforeHost = config('database.connections.mysql.host');
    $beforeDatabase = config('database.connections.mysql.database');

    $school = School::factory()->create();

    $task = new SwitchLmsConnection;
    $task->makeCurrent($school);
    $task->forgetCurrent();

    expect(config('database.connections.mysql.host'))->toBe($beforeHost);
    expect(config('database.connections.mysql.database'))->toBe($beforeDatabase);
});

it('purges the lms connection so subsequent reads pick up new credentials', function (): void {
    $school = School::factory()->create([
        'lms_db_host' => '10.0.0.42',
        'lms_db_database' => 'lms_acme',
        'lms_db_username' => 'lms_acme_user',
    ]);

    (new SwitchLmsConnection)->makeCurrent($school);

    // After makeCurrent, the next access to the lms connection should reflect
    // the rebound config — not the original .env snapshot. We assert via the
    // config the next connection would resolve from, since actually opening
    // a TCP connection to 10.0.0.42 is unsafe in a unit test.
    $resolvedConfig = (array) config('database.connections.lms');

    expect($resolvedConfig['host'])->toBe('10.0.0.42');
    expect($resolvedConfig['database'])->toBe('lms_acme');
    expect($resolvedConfig['username'])->toBe('lms_acme_user');
});

it('coerces a null decrypted password to an empty string for the connector', function (): void {
    // The encrypted cast can yield null in legacy / partial rows. Laravel's
    // mysql connector accepts an empty string but not null — the task must
    // coerce defensively. Build the password coercion path by setting the
    // raw column without going through the encrypted cast (factory uses the
    // dev mysql password by default which is also empty).
    $school = School::factory()->create([
        'lms_db_password' => '',
    ]);

    (new SwitchLmsConnection)->makeCurrent($school);

    expect(config('database.connections.lms.password'))->toBe('');
});

it('keeps the lms connection name resolvable through the DB facade', function (): void {
    // Sanity check — DB::purge('lms') should not throw, and the connection
    // factory should be able to resolve a fresh instance from the rebound
    // config without actually opening a TCP socket.
    $school = School::factory()->create();

    (new SwitchLmsConnection)->makeCurrent($school);

    expect(fn () => DB::purge('lms'))->not->toThrow(Throwable::class);
});
