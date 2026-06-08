<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Slice C — auth + RBAC, Phase A.2 update.
 *
 * After Phase A.2:
 *   - App\Models\User lives on pas_users (payroll-DB-owned).
 *   - The LMS is the identity master; AssignPayrollRoleOnLogin resolves the
 *     LMS role_id via pas_users.lms_user_id → lms.users.role_id.
 *   - The User model no longer throws LmsWriteException — pas_users is
 *     fully app-writable. The read-only contract on the LMS side is still
 *     enforced exhaustively by App\Models\Lms\ReadOnlyModel (see
 *     LmsReadOnlyTest).
 *   - Password reset is disabled (LMS handles resets out-of-band); the
 *     "Fortify password reset write-through" assertion was removed.
 *
 * The factory's withLmsRole($roleId) writes the role_id onto the LMS users
 * mirror row (test sqlite, kept in sync by UserFactory::configure()).
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

it('does not assign any role when pas_users has no lms_user_id cross-reference', function () {
    $user = User::factory()->withoutLmsMirror()->create();

    Event::dispatch(new Login('web', $user, false));

    // No lms_user_id => listener returns early without assigning.
    expect($user->fresh()->roles->isEmpty())->toBeTrue();
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
