<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;

/**
 * Keeps a pay period's status in lockstep with its payroll lifecycle.
 *
 * Posting a run is the point of no return for the money, so it CLOSES the
 * period — it drops out of the "Generate payroll" picker and reads as
 * finished on the pay-periods list. Deleting that posted run (the DEMO
 * hard-delete) REOPENS the period. Voiding needs no handling: a posted run
 * can never be voided (posted is terminal), and non-posted runs never
 * closed the period in the first place.
 */
final class PayrollRunObserver
{
    public function updated(PayrollRun $run): void
    {
        if ($run->wasChanged('status') && $run->status === PayrollRun::STATUS_POSTED) {
            $this->setPeriodStatus($run->pay_period_id, PayPeriod::STATUS_CLOSED);
        }
    }

    public function deleted(PayrollRun $run): void
    {
        if ($run->status === PayrollRun::STATUS_POSTED) {
            $this->setPeriodStatus($run->pay_period_id, PayPeriod::STATUS_OPEN);
        }
    }

    /**
     * Flip the period's status via the query builder — no model events, so
     * no recursion and no duplicate audit entry for a system-driven change.
     */
    private function setPeriodStatus(int $payPeriodId, string $status): void
    {
        PayPeriod::query()
            ->whereKey($payPeriodId)
            ->update(['status' => $status]);
    }
}
