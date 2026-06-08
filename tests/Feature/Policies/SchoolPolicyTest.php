<?php

declare(strict_types=1);

use App\Models\Pas\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Pins the role × ability matrix for SchoolPolicy.
 *
 *   - platform-admin (payroll-native, lms_user_id IS NULL) can do everything
 *     (viewAny, view, create, update, delete).
 *   - LMS-derived super-admin (lms_user_id NOT NULL) is DENIED — cross-tenant
 *     powers are now exclusive to platform-admin. School-scoped super-admin
 *     keeps its meaning at school level (approve payroll runs etc.) but no
 *     longer manages the schools registry.
 *   - every other role (payroll-officer, hr, auditor, employee) gets a flat
 *     denial across the board because tenant onboarding is a system-config
 *     task, and LMS DB credentials live on these rows.
 *   - a user with no roles is also denied — the `before()` hook returns
 *     null for them and the explicit method returns false.
 *   - a payroll-native user who somehow holds platform-admin role must also
 *     have lms_user_id IS NULL; the defense-in-depth check denies an LMS-
 *     derived row even with the platform-admin role assigned.
 */

function schoolPolicyAuthAs(?string $role): User
{
    $user = User::factory()->create();
    if ($role !== null) {
        $user->syncRoles([$role]);
    }

    return $user;
}

function schoolPolicyPlatformAdmin(): User
{
    $user = User::factory()->withoutLmsMirror()->create();
    $user->syncRoles(['platform-admin']);

    return $user->fresh() ?? $user;
}

it('allows platform-admin (payroll-native) every ability', function (): void {
    $user = schoolPolicyPlatformAdmin();
    $school = School::factory()->create();

    expect($user->can('viewAny', School::class))->toBeTrue()
        ->and($user->can('view', $school))->toBeTrue()
        ->and($user->can('create', School::class))->toBeTrue()
        ->and($user->can('update', $school))->toBeTrue()
        ->and($user->can('delete', $school))->toBeTrue();
});

it('denies every ability for LMS-derived users regardless of role', function (string $role): void {
    $user = schoolPolicyAuthAs($role);
    $school = School::factory()->create();

    expect($user->can('viewAny', School::class))->toBeFalse()
        ->and($user->can('view', $school))->toBeFalse()
        ->and($user->can('create', School::class))->toBeFalse()
        ->and($user->can('update', $school))->toBeFalse()
        ->and($user->can('delete', $school))->toBeFalse();
})->with([
    'super-admin',
    'payroll-officer',
    'hr',
    'auditor',
    'employee',
]);

it('denies every ability for a user with no roles', function (): void {
    $user = schoolPolicyAuthAs(null);
    $school = School::factory()->create();

    expect($user->can('viewAny', School::class))->toBeFalse()
        ->and($user->can('view', $school))->toBeFalse()
        ->and($user->can('create', School::class))->toBeFalse()
        ->and($user->can('update', $school))->toBeFalse()
        ->and($user->can('delete', $school))->toBeFalse();
});

it('denies every ability for an LMS-derived user with the platform-admin role', function (): void {
    // Defense in depth: even if an LMS-derived user gets the platform-admin
    // role assigned (operator error or seed mismatch), the lms_user_id IS
    // NULL gate denies them. Mirrors the same gate in ApplyTenantOverride
    // and HandleInertiaRequests.
    $user = schoolPolicyAuthAs('platform-admin');
    $school = School::factory()->create();

    expect($user->getAttribute('lms_user_id'))->not->toBeNull();
    expect($user->can('viewAny', School::class))->toBeFalse()
        ->and($user->can('view', $school))->toBeFalse()
        ->and($user->can('create', School::class))->toBeFalse()
        ->and($user->can('update', $school))->toBeFalse()
        ->and($user->can('delete', $school))->toBeFalse();
});
