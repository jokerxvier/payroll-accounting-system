<?php

declare(strict_types=1);

use App\Actions\Payroll\ApprovePayrollRunAction;
use App\Actions\Payroll\GeneratePayrollRunAction;
use App\Actions\Payroll\PostPayrollRunAction;
use App\Actions\Payroll\SubmitPayrollRunForApprovalAction;
use App\Actions\Payroll\VoidPayrollRunAction;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Pin the Phase 3 W10 state machine:
 *   computed → pending_approval → approved → posted
 *                                              ↘ voided (any non-posted)
 *
 * Each action stamps the matching `*_at` and `*_by_user_id`. Bad
 * transitions throw DomainException — the controller-level Gate is
 * the primary guard, but the action defends against direct misuse from
 * jobs/console/tinker.
 */

it('submit advances computed → pending_approval and stamps the actor', function () {
    $user = User::factory()->create();
    $run = PayrollRun::factory()->computed()->create();

    $result = app(SubmitPayrollRunForApprovalAction::class)->execute($run, $user->id);

    expect($result->status)->toBe(PayrollRun::STATUS_PENDING_APPROVAL)
        ->and($result->submitted_at)->not->toBeNull()
        ->and($result->submitted_by_user_id)->toBe($user->id);
});

it('submit refuses to advance from a non-computed status', function () {
    $user = User::factory()->create();
    $run = PayrollRun::factory()->create(['status' => PayrollRun::STATUS_DRAFT]);

    expect(fn () => app(SubmitPayrollRunForApprovalAction::class)->execute($run, $user->id))
        ->toThrow(DomainException::class);
});

it('approve advances pending_approval → approved and stamps the actor', function () {
    $user = User::factory()->create();
    $run = PayrollRun::factory()->create(['status' => PayrollRun::STATUS_PENDING_APPROVAL]);

    $result = app(ApprovePayrollRunAction::class)->execute($run, $user->id);

    expect($result->status)->toBe(PayrollRun::STATUS_APPROVED)
        ->and($result->approved_at)->not->toBeNull()
        ->and($result->approved_by_user_id)->toBe($user->id)
        ->and($result->isLocked())->toBeTrue();
});

it('approve refuses from any non-pending status', function () {
    $user = User::factory()->create();
    $run = PayrollRun::factory()->computed()->create();

    expect(fn () => app(ApprovePayrollRunAction::class)->execute($run, $user->id))
        ->toThrow(DomainException::class);
});

it('post advances approved → posted and stamps the actor', function () {
    $user = User::factory()->create();
    $run = PayrollRun::factory()->approved()->create();

    $result = app(PostPayrollRunAction::class)->execute($run, $user->id);

    expect($result->status)->toBe(PayrollRun::STATUS_POSTED)
        ->and($result->posted_at)->not->toBeNull()
        ->and($result->posted_by_user_id)->toBe($user->id)
        ->and($result->isLocked())->toBeTrue();
});

it('post refuses from any non-approved status', function () {
    $user = User::factory()->create();
    $run = PayrollRun::factory()->computed()->create();

    expect(fn () => app(PostPayrollRunAction::class)->execute($run, $user->id))
        ->toThrow(DomainException::class);
});

it('void from any non-posted, non-voided status moves to voided', function (string $startStatus) {
    $user = User::factory()->create();
    $run = PayrollRun::factory()->create(['status' => $startStatus]);

    $result = app(VoidPayrollRunAction::class)->execute($run, $user->id);

    expect($result->status)->toBe(PayrollRun::STATUS_VOIDED)
        ->and($result->voided_at)->not->toBeNull()
        ->and($result->voided_by_user_id)->toBe($user->id)
        ->and($result->isLocked())->toBeTrue();
})->with([
    PayrollRun::STATUS_DRAFT,
    PayrollRun::STATUS_COMPUTED,
    PayrollRun::STATUS_PENDING_APPROVAL,
    PayrollRun::STATUS_APPROVED,
]);

it('void refuses on a posted run', function () {
    $user = User::factory()->create();
    $run = PayrollRun::factory()->create(['status' => PayrollRun::STATUS_POSTED]);

    expect(fn () => app(VoidPayrollRunAction::class)->execute($run, $user->id))
        ->toThrow(DomainException::class);
});

it('void refuses on an already-voided run', function () {
    $user = User::factory()->create();
    $run = PayrollRun::factory()->voided()->create();

    expect(fn () => app(VoidPayrollRunAction::class)->execute($run, $user->id))
        ->toThrow(DomainException::class);
});

it('GeneratePayrollRunAction refuses to run against a period locked by an approved run', function () {
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    PayrollRun::factory()->approved()->create(['pay_period_id' => $period->id]);

    expect(fn () => app(GeneratePayrollRunAction::class)->execute($period))
        ->toThrow(DomainException::class);
});

it('GeneratePayrollRunAction refuses to run against a period locked by a posted run', function () {
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    PayrollRun::factory()->create([
        'pay_period_id' => $period->id,
        'status' => PayrollRun::STATUS_POSTED,
    ]);

    expect(fn () => app(GeneratePayrollRunAction::class)->execute($period))
        ->toThrow(DomainException::class);
});

it('GeneratePayrollRunAction allows a fresh run after the existing run is voided', function () {
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    PayrollRun::factory()->voided()->create(['pay_period_id' => $period->id]);

    // No exception — voided runs do not lock the period.
    $run = app(GeneratePayrollRunAction::class)->execute($period);
    expect($run->id)->not->toBeNull();
});

it('isLocked matrix per status', function (string $status, bool $expected) {
    $run = PayrollRun::factory()->create(['status' => $status]);
    expect($run->isLocked())->toBe($expected);
})->with([
    [PayrollRun::STATUS_DRAFT, false],
    [PayrollRun::STATUS_COMPUTING, false],
    [PayrollRun::STATUS_COMPUTED, false],
    [PayrollRun::STATUS_PENDING_APPROVAL, false],
    [PayrollRun::STATUS_APPROVED, true],
    [PayrollRun::STATUS_POSTED, true],
    [PayrollRun::STATUS_VOIDED, true],
]);
