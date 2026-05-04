<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Pins the role + edit-eligibility matrix for StatutoryContributionPolicy.
 *
 *   - super-admin is the only role that can do anything on this surface
 *     (matches the existing Admin\StatutoryContributionIndexTest.php and
 *     Admin\StatutoryContributionStoreTest.php contract).
 *   - update + void additionally require $row->isEditable() — voided or
 *     past-dated or superseded rows reject mutation even for super-admin.
 *   - Every other role (payroll-officer, hr, auditor, employee) gets a flat
 *     denial across the board.
 */

function policyAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

function editableContribution(): StatutoryContribution
{
    return StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => null,
    ]);
}

it('allows super-admin every action on an editable row', function () {
    $user = policyAuthAs('super-admin');
    $row = editableContribution();

    expect($user->can('viewAny', StatutoryContribution::class))->toBeTrue()
        ->and($user->can('view', $row))->toBeTrue()
        ->and($user->can('create', StatutoryContribution::class))->toBeTrue()
        ->and($user->can('update', $row))->toBeTrue()
        ->and($user->can('void', $row))->toBeTrue();
});

it('denies update and void on a past-dated row even for super-admin', function () {
    $user = policyAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'voided_at' => null,
    ]);

    expect($user->can('view', $row))->toBeTrue()
        ->and($user->can('update', $row))->toBeFalse()
        ->and($user->can('void', $row))->toBeFalse();
});

it('denies update and void on a superseded row even for super-admin', function () {
    $user = policyAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => CarbonImmutable::now()->addYears(1)->toDateString(),
        'voided_at' => null,
    ]);

    expect($user->can('update', $row))->toBeFalse()
        ->and($user->can('void', $row))->toBeFalse();
});

it('denies update and void on a voided row even for super-admin', function () {
    $user = policyAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => CarbonImmutable::now(),
    ]);

    expect($user->can('update', $row))->toBeFalse()
        ->and($user->can('void', $row))->toBeFalse();
});

it('denies every action for non-super-admin roles', function (string $role) {
    $user = policyAuthAs($role);
    $row = editableContribution();

    expect($user->can('viewAny', StatutoryContribution::class))->toBeFalse()
        ->and($user->can('view', $row))->toBeFalse()
        ->and($user->can('create', StatutoryContribution::class))->toBeFalse()
        ->and($user->can('update', $row))->toBeFalse()
        ->and($user->can('void', $row))->toBeFalse();
})->with([
    'payroll-officer',
    'hr',
    'auditor',
    'employee',
]);
