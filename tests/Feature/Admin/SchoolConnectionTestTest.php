<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * /admin/schools/test-connection JSON endpoint.
 *
 * Pinned behaviors:
 *  - Auth gate: platform-admin only (payroll-native, no LMS counterpart);
 *    every other role + guest is denied. Cross-tenant powers, including
 *    "test the LMS connection for a different school", are reserved for
 *    platform-admin — school-scoped super-admin lost this in the multi-
 *    tenant role-separation slice.
 *  - Validation: required fields surface as 422.
 *  - Failure path: invalid host returns ok=false with a non-empty message.
 *  - Redaction: when the literal password value appears in the underlying
 *    driver/socket error message, it is replaced with '***' before return.
 *
 * The success path is best-effort — it's only meaningful against a live
 * MySQL DB. The test suite runs on sqlite by default; we skip the success
 * test in that environment rather than fail.
 */

function connTestAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

function connTestPlatformAdmin(): User
{
    $user = User::factory()->withoutLmsMirror()->create();
    $user->syncRoles(['platform-admin']);

    return $user->fresh() ?? $user;
}

/** @return array<string, mixed> */
function connTestPayload(array $overrides = []): array
{
    return array_merge([
        'lms_db_host' => '127.0.0.1.invalid',  // RFC2606-style guarantee of failure
        'lms_db_port' => 3306,
        'lms_db_database' => 'fake_db',
        'lms_db_username' => 'fake_user',
        'lms_db_password' => 'fake-password',
        'lms_db_charset' => 'utf8mb4',
    ], $overrides);
}

it('returns ok=false with a non-empty message on invalid host', function (): void {
    $user = connTestPlatformAdmin();

    $response = $this->actingAs($user)
        ->postJson('/admin/schools/test-connection', connTestPayload());

    $response->assertOk();
    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('message'))->toBeString()
        ->and(strlen((string) $response->json('message')))->toBeGreaterThan(0);
});

it('redacts the password from the error message when it leaks through the driver', function (): void {
    $user = connTestPlatformAdmin();

    $secret = 'redact-me-secret-xyz-payroll';
    $response = $this->actingAs($user)
        ->postJson('/admin/schools/test-connection', connTestPayload([
            'lms_db_password' => $secret,
        ]));

    $response->assertOk();
    expect($response->json('ok'))->toBeFalse();

    // Whether or not the driver echoes the password back depends on the
    // failure mode. If it did, the controller must scrub it. We assert
    // the *invariant*: the literal secret never appears in the response.
    $message = (string) $response->json('message');
    expect($message)->not->toContain($secret);
});

it('forbids the endpoint for non-platform-admin roles', function (string $role): void {
    $user = connTestAuthAs($role);

    $this->actingAs($user)
        ->postJson('/admin/schools/test-connection', connTestPayload())
        ->assertForbidden();
})->with(['super-admin', 'payroll-officer', 'hr', 'auditor', 'employee']);

it('redirects guests away from the endpoint', function (): void {
    $this->post('/admin/schools/test-connection', connTestPayload())
        ->assertRedirect('/login');
});

it('rejects a request with missing required fields', function (): void {
    $user = connTestPlatformAdmin();

    $this->actingAs($user)
        ->postJson('/admin/schools/test-connection', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'lms_db_host',
            'lms_db_port',
            'lms_db_database',
            'lms_db_username',
            'lms_db_password',
            'lms_db_charset',
        ]);
});

it('returns ok=true against a reachable MySQL connection', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Success path requires a real MySQL connection.');
    }

    $user = connTestPlatformAdmin();

    $mysql = (array) config('database.connections.mysql', []);
    $response = $this->actingAs($user)
        ->postJson('/admin/schools/test-connection', [
            'lms_db_host' => (string) ($mysql['host'] ?? '127.0.0.1'),
            'lms_db_port' => (int) ($mysql['port'] ?? 3306),
            'lms_db_database' => (string) ($mysql['database'] ?? 'payroll_db'),
            'lms_db_username' => (string) ($mysql['username'] ?? 'root'),
            'lms_db_password' => (string) ($mysql['password'] ?? ''),
            'lms_db_charset' => 'utf8mb4',
        ]);

    $response->assertOk();
    expect($response->json('ok'))->toBeTrue();
});
