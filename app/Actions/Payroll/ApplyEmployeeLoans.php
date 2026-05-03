<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Services\Payroll\Breakdowns\LoansBreakdown;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Resolve the open salary-loan amortizations that fire on a single pay
 * period.
 *
 * Algorithm:
 *
 *   1. Load every {@see EmployeeLoan} that
 *      a) belongs to the given profile,
 *      b) is `open` — `closed_on IS NULL AND outstanding_balance_centavos > 0`,
 *         and
 *      c) has a `schedule` value that fires on this period (the matrix is
 *         shared with EmployeeDeduction — `EmployeeLoan` does not own its
 *         own scope helper because the schedule semantics are identical).
 *
 *   2. For each loan compute the next-amortization preview as
 *      `min(monthly_amortization, outstanding_balance)`. Comparison and
 *      clamp via `Money::greaterThan` / `Money::minus` — never via raw
 *      `int` math, so the audit lines round-trip cleanly through the
 *      Money value object.
 *
 *   3. Emit one {@see PayrollLineItem} per loan, code
 *      {@see PayrollLineItem::CODE_LOAN_AMORTIZATION}, bucket
 *      `BUCKET_EMPLOYEE_DEDUCTION`. The label is the loan's `code`
 *      (e.g., `LN-2024-001`); `meta` carries `loan_id` and the
 *      `outstanding_balance_centavos` snapshot for the audit trail.
 *
 * **Read-only**: this action MUST NOT call
 * {@see EmployeeLoan::applyAmortization()} or persist any state. The
 * actual decrement of `outstanding_balance_centavos` happens in the
 * Week-9 batch persistence path inside a `SELECT ... FOR UPDATE`
 * transaction. The compute path runs many times per draft preview and
 * any mutation here would corrupt the loan ledger.
 */
final class ApplyEmployeeLoans
{
    public function __invoke(EmployeeProfile $profile, PayPeriodInput $period): LoansBreakdown
    {
        /** @var list<EmployeeLoan> $loans */
        $loans = EmployeeLoan::query()
            ->where('employee_profile_id', $profile->id)
            ->open()
            ->whereIn('schedule', EmployeeDeduction::schedulesMatching($period))
            ->get()
            ->all();

        if ($loans === []) {
            return LoansBreakdown::zero();
        }

        $total = Money::zero();
        $lines = [];

        foreach ($loans as $loan) {
            $monthly = Money::fromCentavos($loan->monthly_amortization_centavos);
            $outstanding = Money::fromCentavos($loan->outstanding_balance_centavos);

            // Clamp to outstanding: cannot pay more than is owed. The
            // `scopeOpen()` filter guarantees outstanding > 0, so the
            // emitted amount is always strictly positive.
            $amount = $monthly->greaterThan($outstanding) ? $outstanding : $monthly;

            $total = $total->plus($amount);

            $lines[] = new PayrollLineItem(
                code: PayrollLineItem::CODE_LOAN_AMORTIZATION,
                label: $loan->code,
                amount: $amount,
                bucket: PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                meta: [
                    'loan_id' => $loan->id,
                    'outstanding_balance_centavos' => $loan->outstanding_balance_centavos,
                ],
            );
        }

        return new LoansBreakdown(
            total: $total,
            lines: $lines,
        );
    }
}
