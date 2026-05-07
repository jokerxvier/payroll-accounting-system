<?php

declare(strict_types=1);

use App\Models\Pas\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Pins the role × ability matrix for SchoolPolicy.
 *
 *   - super-admin can do everything (viewAny, view, create, update, delete).
 *   - every other role (payroll-officer, hr, auditor, employee) gets a flat
 *     denial across the board because tenant onboarding is a system-config
 *     task, and LMS DB credentials live on these rows.
 *   - a user with no roles is also denied — the `before()` hook returns
 *     null for them and the explicit method returns false.
 */

function schoolPolicyAuthAs(?string $role): User
{
    $user = User::factory()->create();
    if ($role !== null) {
        $user->syncRoles([$role]);
    }

    return $user;
}

it('allows super-admin every ability', function (): void {
    $user = schoolPolicyAuthAs('super-admin');
    $school = School::factory()->create();

    expect($user->can('viewAny', School::class))->toBeTrue()
        ->and($user->can('view', $school))->toBeTrue()
        ->and($user->can('create', School::class))->toBeTrue()
        ->and($user->can('update', $school))->toBeTrue()
        ->and($user->can('delete', $school))->toBeTrue();
});

it('denies every ability for non-super-admin roles', function (string $role): void {
    $user = schoolPolicyAuthAs($role);
    $school = School::factory()->create();

    expect($user->can('viewAny', School::class))->toBeFalse()
        ->and($user->can('view', $school))->toBeFalse()
        ->and($user->can('create', School::class))->toBeFalse()
        ->and($user->can('update', $school))->toBeFalse()
        ->and($user->can('delete', $school))->toBeFalse();
})->with([
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
