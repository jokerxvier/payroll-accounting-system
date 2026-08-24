<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\AuditLog;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * POST /employees/{staffId}/profile creates a payroll profile for the staff
 * id from the four values the setup sheet collects. The endpoint is
 * idempotent — POSTing twice does not duplicate the row.
 *
 * These tests previously posted an empty body, from an older contract where
 * the endpoint filled the row from the migration's column defaults.
 * EmployeeProfileStoreRequest requires all four fields now, matching what
 * `resources/js/components/employees/profile-setup-sheet.tsx` actually sends.
 *
 * The empty body went unnoticed because a validation failure redirects BACK
 * to the same URL, so `assertRedirect()` could not tell it apart from
 * success. Every write below therefore also asserts the session carries no
 * errors — without that, this file passes while creating nothing.
 */

/**
 * The payload the setup sheet posts. Mirrors DEFAULTS in
 * profile-setup-sheet.tsx, except pay_frequency, which is exercised here as
 * semi_monthly to prove the submitted value is what persists.
 *
 * @return array<string, mixed>
 */
function validProfilePayload(array $overrides = []): array
{
    return array_merge([
        'basic_salary_centavos' => 0,
        'employment_classification' => 'regular',
        'pay_frequency' => 'semi_monthly',
        'is_active' => true,
    ], $overrides);
}

function authStoreAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('creates a payroll profile from the submitted values', function () {
    $user = authStoreAs('super-admin');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();
    expect(EmployeeProfile::query()->where('lms_staff_id', $staff->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->from('/employees/'.(int) $staff->id)
        ->post('/employees/'.(int) $staff->id.'/profile', validProfilePayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect('/employees/'.(int) $staff->id);

    $profile = EmployeeProfile::query()->where('lms_staff_id', $staff->id)->first();

    expect($profile)->not->toBeNull()
        ->and($profile->employment_classification)->toBe('regular')
        ->and($profile->pay_frequency)->toBe('semi_monthly')
        ->and((int) $profile->basic_salary_centavos)->toBe(0)
        ->and($profile->is_active)->toBeTrue();
});

it('writes a created audit row when creating a profile', function () {
    $user = authStoreAs('super-admin');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    $this->actingAs($user)
        ->post('/employees/'.(int) $staff->id.'/profile', validProfilePayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $profile = EmployeeProfile::query()->where('lms_staff_id', $staff->id)->firstOrFail();

    $rows = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profile->id)
        ->where('action', 'created')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->actor_id)->toBe($user->id);
});

it('is idempotent — posting twice does not duplicate the profile', function () {
    $user = authStoreAs('super-admin');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    $this->actingAs($user)
        ->post('/employees/'.(int) $staff->id.'/profile', validProfilePayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($user)
        ->post('/employees/'.(int) $staff->id.'/profile', validProfilePayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(EmployeeProfile::query()->where('lms_staff_id', $staff->id)->count())->toBe(1);
});

it('forbids the auditor role on create', function () {
    $user = authStoreAs('auditor');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    // Valid payload on purpose: the 403 must come from authorize(), not from
    // validation happening to reject an empty body.
    $this->actingAs($user)
        ->post('/employees/'.(int) $staff->id.'/profile', validProfilePayload())
        ->assertForbidden();
});

it('forbids the employee role on create', function () {
    $user = authStoreAs('employee');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    $this->actingAs($user)
        ->post('/employees/'.(int) $staff->id.'/profile', validProfilePayload())
        ->assertForbidden();
});

it('returns 404 for an unknown staff id', function () {
    $user = authStoreAs('super-admin');

    // The body has to be valid, or validation 302s before the controller
    // ever gets to decide the staff does not exist.
    $this->actingAs($user)
        ->post('/employees/9999999/profile', validProfilePayload())
        ->assertNotFound();
});

it('returns 404 for a staff id whose role is not in the payroll allowlist', function () {
    $user = authStoreAs('super-admin');

    config(['payroll.employee_role_allowlist' => [1]]);

    $staff = Staff::query()->where('role_id', 4)->first();
    expect($staff)->not->toBeNull();

    $this->actingAs($user)
        ->post('/employees/'.(int) $staff->id.'/profile', validProfilePayload())
        ->assertNotFound();

    expect(EmployeeProfile::query()->where('lms_staff_id', $staff->id)->exists())->toBeFalse();
});
