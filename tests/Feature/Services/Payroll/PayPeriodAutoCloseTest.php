<?php

declare(strict_types=1);

use App\Actions\Payroll\PostPayrollRunAction;
use App\Actions\Payroll\VoidPayrollRunAction;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * PayrollRunObserver keeps PayPeriod.status in lockstep with the payroll
 * lifecycle: posting closes the period, deleting a posted run reopens it.
 */

it('closes the pay period when its run is posted', function () {
    $user = User::factory()->create();
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    $run = PayrollRun::factory()->computed()->create(['pay_period_id' => $period->id]);

    app(PostPayrollRunAction::class)->execute($run, $user->id);

    expect($period->fresh()->status)->toBe(PayPeriod::STATUS_CLOSED);
});

it('reopens the pay period when a posted run is deleted', function () {
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    $run = PayrollRun::factory()->create([
        'pay_period_id' => $period->id,
        'status' => PayrollRun::STATUS_POSTED,
    ]);
    // Sync the period to the state a posted run would have left it in.
    $period->forceFill(['status' => PayPeriod::STATUS_CLOSED])->save();

    $run->delete();

    expect($period->fresh()->status)->toBe(PayPeriod::STATUS_OPEN);
});

it('leaves the pay period open when a non-posted run is voided or deleted', function () {
    $user = User::factory()->create();
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    $run = PayrollRun::factory()->computed()->create(['pay_period_id' => $period->id]);

    app(VoidPayrollRunAction::class)->execute($run, $user->id);
    expect($period->fresh()->status)->toBe(PayPeriod::STATUS_OPEN);

    $run->fresh()->delete();
    expect($period->fresh()->status)->toBe(PayPeriod::STATUS_OPEN);
});
