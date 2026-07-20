<?php

declare(strict_types=1);

use App\Models\Pas\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Pins the maker-checker contract for PayrollRunPolicy.
 *
 *   - Maker roles (super-admin, payroll-officer, hr) own viewAny / view /
 *     create / submit. Submitting a computed run for approval is the maker's
 *     final action.
 *   - Approval roles (super-admin only) own approve / post / void. Approval
 *     reviews and stamps; post is the disbursement transition; void unwinds
 *     a non-posted run.
 *   - Status guards on submit/approve/post still bite — the action is only
 *     legal at the matching status.
 */

function userWithRole(?string $role): User
{
    $user = User::factory()->create();

    if ($role !== null) {
        $user->syncRoles([$role]);
    }

    return $user;
}

function runAt(string $status): PayrollRun
{
    return PayrollRun::factory()->create(['status' => $status]);
}

it('allows maker actions for makers', function (string $role) {
    $user = userWithRole($role);
    $computed = runAt(PayrollRun::STATUS_COMPUTED);

    expect($user->can('viewAny', PayrollRun::class))->toBeTrue()
        ->and($user->can('view', $computed))->toBeTrue()
        ->and($user->can('create', PayrollRun::class))->toBeTrue()
        ->and($user->can('submit', $computed))->toBeTrue();
})->with(['super-admin', 'payroll-officer', 'hr']);

it('denies approver actions for non-super-admin makers', function (string $role) {
    $user = userWithRole($role);
    $pending = runAt(PayrollRun::STATUS_PENDING_APPROVAL);
    $approved = runAt(PayrollRun::STATUS_APPROVED);
    $draft = runAt(PayrollRun::STATUS_DRAFT);

    expect($user->can('approve', $pending))->toBeFalse()
        ->and($user->can('post', $approved))->toBeFalse()
        ->and($user->can('void', $draft))->toBeFalse();
})->with(['payroll-officer', 'hr']);

it('allows every action for super-admin', function () {
    $user = userWithRole('super-admin');
    $computed = runAt(PayrollRun::STATUS_COMPUTED);
    $pending = runAt(PayrollRun::STATUS_PENDING_APPROVAL);
    $approved = runAt(PayrollRun::STATUS_APPROVED);
    $draft = runAt(PayrollRun::STATUS_DRAFT);

    expect($user->can('viewAny', PayrollRun::class))->toBeTrue()
        ->and($user->can('view', $computed))->toBeTrue()
        ->and($user->can('create', PayrollRun::class))->toBeTrue()
        ->and($user->can('submit', $computed))->toBeTrue()
        ->and($user->can('approve', $pending))->toBeTrue()
        ->and($user->can('post', $approved))->toBeTrue()
        ->and($user->can('void', $draft))->toBeTrue();
});

it('denies every action for auditor, employee, and unroled users', function (?string $role) {
    $user = userWithRole($role);
    $computed = runAt(PayrollRun::STATUS_COMPUTED);
    $pending = runAt(PayrollRun::STATUS_PENDING_APPROVAL);
    $approved = runAt(PayrollRun::STATUS_APPROVED);

    expect($user->can('viewAny', PayrollRun::class))->toBeFalse()
        ->and($user->can('view', $computed))->toBeFalse()
        ->and($user->can('create', PayrollRun::class))->toBeFalse()
        ->and($user->can('submit', $computed))->toBeFalse()
        ->and($user->can('approve', $pending))->toBeFalse()
        ->and($user->can('post', $approved))->toBeFalse();
})->with(['auditor', 'employee', null]);

it('respects status guard on submit', function () {
    $user = userWithRole('payroll-officer');

    expect($user->can('submit', runAt(PayrollRun::STATUS_DRAFT)))->toBeFalse()
        ->and($user->can('submit', runAt(PayrollRun::STATUS_COMPUTED)))->toBeTrue()
        ->and($user->can('submit', runAt(PayrollRun::STATUS_PENDING_APPROVAL)))->toBeFalse()
        ->and($user->can('submit', runAt(PayrollRun::STATUS_APPROVED)))->toBeFalse();
});

it('respects status guard on approve', function () {
    $user = userWithRole('super-admin');

    expect($user->can('approve', runAt(PayrollRun::STATUS_COMPUTED)))->toBeFalse()
        ->and($user->can('approve', runAt(PayrollRun::STATUS_PENDING_APPROVAL)))->toBeTrue()
        ->and($user->can('approve', runAt(PayrollRun::STATUS_APPROVED)))->toBeFalse();
});

it('respects status guard on post', function () {
    $user = userWithRole('super-admin');

    // DEMO: approval bypassed — a computed run is directly postable.
    expect($user->can('post', runAt(PayrollRun::STATUS_COMPUTED)))->toBeTrue()
        ->and($user->can('post', runAt(PayrollRun::STATUS_PENDING_APPROVAL)))->toBeFalse()
        ->and($user->can('post', runAt(PayrollRun::STATUS_APPROVED)))->toBeTrue()
        ->and($user->can('post', runAt(PayrollRun::STATUS_POSTED)))->toBeFalse();
});

it('allows makers to delete a run at any status (DEMO)', function (string $role) {
    $user = userWithRole($role);

    expect($user->can('delete', runAt(PayrollRun::STATUS_COMPUTED)))->toBeTrue()
        ->and($user->can('delete', runAt(PayrollRun::STATUS_POSTED)))->toBeTrue();
})->with(['super-admin', 'payroll-officer', 'hr']);

it('denies delete for non-maker roles', function (?string $role) {
    $user = userWithRole($role);

    expect($user->can('delete', runAt(PayrollRun::STATUS_COMPUTED)))->toBeFalse();
})->with(['auditor', 'employee', null]);

it('blocks void on posted and voided runs', function () {
    $user = userWithRole('super-admin');

    expect($user->can('void', runAt(PayrollRun::STATUS_POSTED)))->toBeFalse()
        ->and($user->can('void', runAt(PayrollRun::STATUS_VOIDED)))->toBeFalse()
        ->and($user->can('void', runAt(PayrollRun::STATUS_DRAFT)))->toBeTrue()
        ->and($user->can('void', runAt(PayrollRun::STATUS_COMPUTED)))->toBeTrue()
        ->and($user->can('void', runAt(PayrollRun::STATUS_APPROVED)))->toBeTrue();
});
