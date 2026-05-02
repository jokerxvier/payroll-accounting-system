<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Exceptions\LmsWriteException;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Slice C — auth + RBAC.
 *
 * These tests cover:
 *  - LMS role_id → payroll Spatie role mapping on Login (allowlisted, mapped)
 *  - fallback to 'employee' when allowlisted but unmapped
 *  - non-assignment when role_id is outside the allowlist
 *  - Fortify password reset is allowed against the LMS users.password column
 *  - non-allowlisted LMS user columns (e.g. name) cannot be updated
 *  - Spatie role assignments persist to pas_model_has_roles
 *
 * Roles are seeded for every Feature test by tests/Pest.php; no per-test
 * setup is required.
 */

it('assigns the mapped payroll role on login', function () {
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5, 6, 13, 15],
        'payroll.lms_role_to_payroll_role' => [
            1 => 'super-admin',
            6 => 'payroll-officer',
            13 => 'payroll-officer',
            15 => 'hr',
        ],
    ]);

    $user = User::factory()->withLmsRole(1)->create();

    // Dispatch the Login event the way Fortify does.
    Event::dispatch(new Login('web', $user, false));

    expect($user->fresh()->hasRole('super-admin'))->toBeTrue();
});

it('falls back to "employee" role when role_id is allowlisted but unmapped', function () {
    config([
        'payroll.employee_role_allowlist' => [4, 7, 8],
        'payroll.lms_role_to_payroll_role' => [
            // role 4 (Teacher) is allowlisted but not present in the map -> employee
        ],
    ]);

    $user = User::factory()->withLmsRole(4)->create();

    Event::dispatch(new Login('web', $user, false));

    expect($user->fresh()->hasRole('employee'))->toBeTrue();
});

it('does not assign any role when role_id is outside the allowlist', function () {
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [
            1 => 'super-admin',
        ],
    ]);

    // role_id = 2 is "Student" — not in the allowlist.
    $user = User::factory()->withLmsRole(2)->create();

    Event::dispatch(new Login('web', $user, false));

    expect($user->fresh()->roles->isEmpty())->toBeTrue();
});

it('allows password reset to write to the LMS users.password column', function () {
    $user = User::factory()->create();
    $originalHash = $user->password;

    $action = new ResetUserPassword;

    // Re-fetch the user so save() takes the "exists, only `password` is dirty"
    // path through the column allowlist guard.
    $reloaded = User::query()->findOrFail($user->id);

    $action->reset($reloaded, [
        'password' => 'new-password-1234',
        'password_confirmation' => 'new-password-1234',
    ]);

    $user->refresh();
    expect($user->password)->not->toBe($originalHash);
});

it('blocks writes to non-allowlisted LMS user columns', function () {
    $user = User::factory()->create();

    // Re-fetch so the model is in "exists" state (the allowlist only kicks in
    // for updates, never for the initial INSERT from the factory).
    $reloaded = User::query()->findOrFail($user->id);
    $reloaded->name = 'Hacked';

    expect(fn () => $reloaded->save())
        ->toThrow(LmsWriteException::class);
});

it('persists Spatie role assignments to pas_model_has_roles', function () {
    $user = User::factory()->create();

    $user->assignRole('payroll-officer');

    $row = DB::table('pas_model_has_roles')
        ->where('model_id', $user->id)
        ->where('model_type', User::class)
        ->first();

    expect($row)->not->toBeNull()
        ->and($user->fresh()->hasRole('payroll-officer'))->toBeTrue();
});
