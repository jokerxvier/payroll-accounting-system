<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;

/**
 * Keeps a pay period's status in lockstep with its payroll lifecycle, and
 * makes a run's destruction fully auditable.
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

    /**
     * Remove the run's payslips through Eloquent before the database gets
     * the chance to cascade them away.
     *
     * `pas_payslips.payroll_run_id` is `cascadeOnDelete`, so deleting a run
     * makes the database drop every child row without Eloquent ever firing
     * `Payslip::deleted` — meaning the AuditObserver never sees them. An
     * irreversible action would then leave a trail showing the run vanished
     * and nothing at all about the payslips it took with it.
     *
     * Deleting them here, one model at a time, gives each payslip its own
     * audit row carrying a full before-snapshot, which is what makes the
     * destruction reconstructable after the fact.
     *
     * Chunked because a run can carry thousands of payslips and this loads
     * each one to fire its events. That cost is accepted: the hard-delete is
     * a rare, deliberate, destructive admin action (and per the controller's
     * own note, a demo affordance that breaks the project's
     * "void, don't delete" convention). If it ever becomes slow enough to
     * matter, it belongs in a queued job rather than losing the audit rows.
     */
    public function deleting(PayrollRun $run): void
    {
        $run->payslips()->chunkById(500, function ($payslips): void {
            foreach ($payslips as $payslip) {
                $payslip->delete();
            }
        });
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
