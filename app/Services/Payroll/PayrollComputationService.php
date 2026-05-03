<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Actions\Payroll\ApplyAllowances;
use App\Actions\Payroll\ApplyEmployeeDeductions;
use App\Actions\Payroll\ApplyEmployeeLoans;
use App\Actions\Payroll\ApplyPayrollAdjustments;
use App\Actions\Payroll\ApplyUnpaidDays;
use App\Actions\Payroll\ComputeBasicPay;
use App\Actions\Payroll\ComputeBirWithholdingTax;
use App\Actions\Payroll\ComputePagibigContribution;
use App\Actions\Payroll\ComputePhilhealthContribution;
use App\Actions\Payroll\ComputeSssContribution;
use App\Models\Pas\EmployeeProfile;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Composes the ten payroll computation actions into a single
 * {@see PayrollComputationResult} for one employee against one pay period.
 *
 * This is the public entry point for the engine: callers (the batch persister
 * in §6F, the payslip preview controller, etc.) interact only with this
 * service. The underlying actions remain individually unit-testable but
 * are never invoked directly by feature code.
 *
 * Algorithm:
 *
 *   1. **Basic pay** — {@see ComputeBasicPay} applies the active / hire-date
 *      / termination-date / pro-ration rules and yields the basic earning for
 *      this period. When this is zero (inactive employee, terminated before
 *      period start, hired after period end, or zero monthly salary) the
 *      entire result is zero — no contribution rows are looked up, no
 *      strategies fire, no DB queries beyond the basic-pay computation.
 *
 *   2. **Unpaid days** — {@see ApplyUnpaidDays} returns a basic-pay reduction
 *      for unpaid leave that fell inside the period. The reduction is
 *      subtracted from `$basicPay` BEFORE any other downstream math sees the
 *      figure. If the post-reduction `$basicPay` is zero we short-circuit to
 *      a canonical zero result — same as the inactive-employee branch above.
 *
 *   3. **Statutory contribution basis** — every contribution is computed
 *      against the FULL monthly salary, not against `basicPay`. Regulators
 *      (SSS, PhilHealth, Pag-IBIG) define their tables monthly; for a
 *      semi-monthly run the four contribution actions internally halve the
 *      result. The basis for a partial month (newly hired employee, etc.) is
 *      still the full monthly salary — the regulators do not pro-rate
 *      contribution bases by tenure. Adding allowances or adjustments DOES
 *      NOT widen the statutory base either (decision 2 in the Week 7 plan).
 *
 *   4. **Allowances + adjustments** — {@see ApplyAllowances} and
 *      {@see ApplyPayrollAdjustments} resolve recurring + one-off earnings
 *      and split each into taxable / non-taxable buckets.
 *
 *   5. **Gross pay** — `basicPay + taxableAllowances + nonTaxableAllowances +
 *      taxableAdjustmentAdditions + nonTaxableAdjustmentAdditions`. Gross
 *      includes ALL earnings; the taxable / non-taxable distinction matters
 *      only for the BIR base downstream.
 *
 *   6. **Custom employee deductions** — {@see ApplyEmployeeDeductions}
 *      resolves recurring custom (non-statutory) deductions. The percent
 *      basis for percent-method rows is the gross computed in step 5.
 *
 *   7. **Loans** — {@see ApplyEmployeeLoans} previews each open loan's
 *      next amortization (clamped to outstanding balance). Read-only at
 *      compute time; the Week-9 batch path mutates balances inside a
 *      transaction.
 *
 *   8. **Taxable income** — `basicPay + taxableAllowances +
 *      taxableAdjustmentAdditions − statutoryEmployeeShares −
 *      customTaxableEmployeeDeductions`. Non-taxable items never enter the
 *      BIR base; loan amortizations and adjustment deductions never reduce
 *      it either (the latter two are post-tax payslip lines).
 *
 *   9. **BIR withholding** — applied against taxable income from step 8.
 *
 *  10. **Net pay** — `gross − totalEmployeeDeductions`. Because gross now
 *      includes non-taxable earnings, the engine no longer adds them
 *      separately to net.
 *
 *  11. **Audit lines** — the engine emits one {@see PayrollLineItem} per
 *      discrete contribution, in a canonical display order: the nine Week-6
 *      lines (basic, employee statutory + BIR, employer statutory) followed
 *      by Week-7 additions in this order: allowances (taxable then
 *      non-taxable), adjustments (additions then deductions), custom
 *      deductions, loans, and finally the unpaid-days line if any.
 *
 * The service is stateless. The actions are constructor-injected via
 * Laravel's auto-wiring — no service-provider binding required.
 */
final class PayrollComputationService
{
    public function __construct(
        private ComputeBasicPay $computeBasicPay,
        private ComputeSssContribution $computeSss,
        private ComputePhilhealthContribution $computePhilhealth,
        private ComputePagibigContribution $computePagibig,
        private ComputeBirWithholdingTax $computeBir,
        private ApplyAllowances $applyAllowances,
        private ApplyEmployeeDeductions $applyEmployeeDeductions,
        private ApplyEmployeeLoans $applyEmployeeLoans,
        private ApplyPayrollAdjustments $applyPayrollAdjustments,
        private ApplyUnpaidDays $applyUnpaidDays,
    ) {}

    public function compute(EmployeeProfile $profile, PayPeriodInput $period): PayrollComputationResult
    {
        // 1. Basic pay handles inactive / out-of-period / pro-ration.
        $basicPay = ($this->computeBasicPay)($profile, $period);

        // 2. Short-circuit when there's nothing to compute. Skipping the
        //    statutory resolver here is what lets a fresh DB (no contribution
        //    rows seeded) still produce a deterministic zero result for an
        //    inactive employee — useful for the batch flow where every active
        //    employee always yields a result and no-pay employees produce a
        //    canonical zero without throwing.
        if ($basicPay->isZero()) {
            return PayrollComputationResult::zero($profile, $period);
        }

        // 3. Unpaid days reduce basic pay before any downstream math sees it.
        //    The action is currently a documented stub returning zero, but the
        //    engine is plumbed for the eventual real implementation. If the
        //    reduction wipes out basic pay entirely, short-circuit to zero —
        //    statutory / BIR / etc. lookups are skipped just like the
        //    inactive-employee branch.
        $reduction = ($this->applyUnpaidDays)($profile, $period, $basicPay);
        $basicPay = $basicPay->minus($reduction->reduction);
        if ($basicPay->isZero()) {
            return PayrollComputationResult::zero($profile, $period);
        }

        // 4. Statutory contribution basis is the FULL monthly salary,
        //    regardless of pay frequency, partial-tenure pro-ration, or
        //    allowances/adjustments riding on this period.
        $monthlyBasis = Money::fromCentavos($profile->basic_salary_centavos);

        $sss = ($this->computeSss)($monthlyBasis, $period);
        $philhealth = ($this->computePhilhealth)($monthlyBasis, $period);
        $pagibig = ($this->computePagibig)($monthlyBasis, $period);

        // 5. Recurring + one-off earnings.
        $allowances = ($this->applyAllowances)($profile, $period);
        $adjustments = ($this->applyPayrollAdjustments)($profile, $period);

        // 6. Gross = basic + ALL earnings (taxable AND non-taxable). The
        //    bucket split matters only for the BIR base downstream.
        $grossPay = $basicPay
            ->plus($allowances->taxable)
            ->plus($allowances->nonTaxable)
            ->plus($adjustments->taxableAdditions)
            ->plus($adjustments->nonTaxableAdditions);

        // 7. Custom deductions: percent rows use the gross from step 6.
        $deductions = ($this->applyEmployeeDeductions)($profile, $period, $grossPay);

        // 8. Loans (read-only preview at compute time).
        $loans = ($this->applyEmployeeLoans)($profile, $period);

        // 9. Taxable income = taxable earnings − statutory employee shares
        //    − custom taxable employee deductions. Non-taxable allowances,
        //    non-taxable adjustment additions, loan amortizations, and
        //    adjustment deductions never enter the BIR base.
        $taxableIncome = $basicPay
            ->plus($allowances->taxable)
            ->plus($adjustments->taxableAdditions)
            ->minus($sss->employeeShare)
            ->minus($philhealth->employeeShare)
            ->minus($pagibig->employeeShare)
            ->minus($deductions->taxableImpact);

        $birTax = ($this->computeBir)($taxableIncome, $period);

        // 10. Aggregates derive from the per-strategy + per-action shares.
        $totalEmployeeDeductions = $sss->employeeShare
            ->plus($philhealth->employeeShare)
            ->plus($pagibig->employeeShare)
            ->plus($birTax)
            ->plus($deductions->employee)
            ->plus($loans->total)
            ->plus($adjustments->deductions);

        $totalEmployerContributions = $sss->employerShare
            ->plus($sss->employerEcShare)
            ->plus($philhealth->employerShare)
            ->plus($pagibig->employerShare)
            ->plus($deductions->employer);

        // 11. Net pay = gross − total employee deductions. Non-taxable items
        //     are already in gross, so no separate "add to net" step.
        $netPay = $grossPay->minus($totalEmployeeDeductions);

        // 12. Canonical audit-line order: the Week-6 nine first, then the
        //     Week-7 additions in deterministic order.
        $auditLines = [
            new PayrollLineItem(
                code: PayrollLineItem::CODE_BASIC_PAY,
                label: 'Basic pay',
                amount: $basicPay,
                bucket: PayrollLineItem::BUCKET_EARNING,
                meta: null,
            ),
            new PayrollLineItem(
                code: PayrollLineItem::CODE_SSS_EMPLOYEE,
                label: 'SSS contribution (employee)',
                amount: $sss->employeeShare,
                bucket: PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                meta: ['contribution_base_centavos' => $sss->taxableAmount->centavos()],
            ),
            new PayrollLineItem(
                code: PayrollLineItem::CODE_PHILHEALTH_EMPLOYEE,
                label: 'PhilHealth premium (employee)',
                amount: $philhealth->employeeShare,
                bucket: PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                meta: ['contribution_base_centavos' => $philhealth->taxableAmount->centavos()],
            ),
            new PayrollLineItem(
                code: PayrollLineItem::CODE_PAGIBIG_EMPLOYEE,
                label: 'Pag-IBIG contribution (employee)',
                amount: $pagibig->employeeShare,
                bucket: PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                meta: ['contribution_base_centavos' => $pagibig->taxableAmount->centavos()],
            ),
            new PayrollLineItem(
                code: PayrollLineItem::CODE_BIR_WITHHOLDING,
                label: 'BIR withholding tax',
                amount: $birTax,
                bucket: PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                meta: ['taxable_income_centavos' => $taxableIncome->centavos()],
            ),
            new PayrollLineItem(
                code: PayrollLineItem::CODE_SSS_EMPLOYER,
                label: 'SSS contribution (employer)',
                amount: $sss->employerShare,
                bucket: PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
            ),
            new PayrollLineItem(
                code: PayrollLineItem::CODE_SSS_EMPLOYER_EC,
                label: "SSS Employees' Compensation (employer)",
                amount: $sss->employerEcShare,
                bucket: PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
            ),
            new PayrollLineItem(
                code: PayrollLineItem::CODE_PHILHEALTH_EMPLOYER,
                label: 'PhilHealth premium (employer)',
                amount: $philhealth->employerShare,
                bucket: PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
            ),
            new PayrollLineItem(
                code: PayrollLineItem::CODE_PAGIBIG_EMPLOYER,
                label: 'Pag-IBIG contribution (employer)',
                amount: $pagibig->employerShare,
                bucket: PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
            ),
        ];

        // Allowances: taxable lines first, then non-taxable. Within a bucket
        // the action's emit order is preserved (insertion order from the
        // subscription query).
        foreach ($allowances->lines as $line) {
            if ($line->code === PayrollLineItem::CODE_ALLOWANCE_TAXABLE) {
                $auditLines[] = $line;
            }
        }
        foreach ($allowances->lines as $line) {
            if ($line->code === PayrollLineItem::CODE_ALLOWANCE_NON_TAXABLE) {
                $auditLines[] = $line;
            }
        }

        // Adjustments: additions first, deductions last.
        foreach ($adjustments->lines as $line) {
            if ($line->code === PayrollLineItem::CODE_ADJUSTMENT_ADDITION) {
                $auditLines[] = $line;
            }
        }
        foreach ($adjustments->lines as $line) {
            if ($line->code === PayrollLineItem::CODE_ADJUSTMENT_DEDUCTION) {
                $auditLines[] = $line;
            }
        }

        // Custom deductions, then loans.
        foreach ($deductions->lines as $line) {
            $auditLines[] = $line;
        }
        foreach ($loans->lines as $line) {
            $auditLines[] = $line;
        }

        // Unpaid days last. The action returns null when no leave applied so
        // payslips for fully-worked periods don't carry a noise zero row.
        if ($reduction->line !== null) {
            $auditLines[] = $reduction->line;
        }

        return new PayrollComputationResult(
            employee: $profile,
            period: $period,
            basicPay: $basicPay,
            grossPay: $grossPay,
            sssEmployee: $sss->employeeShare,
            sssEmployer: $sss->employerShare,
            sssEmployerEc: $sss->employerEcShare,
            philhealthEmployee: $philhealth->employeeShare,
            philhealthEmployer: $philhealth->employerShare,
            pagibigEmployee: $pagibig->employeeShare,
            pagibigEmployer: $pagibig->employerShare,
            birWithholdingTax: $birTax,
            totalEmployeeDeductions: $totalEmployeeDeductions,
            totalEmployerContributions: $totalEmployerContributions,
            taxableIncome: $taxableIncome,
            netPay: $netPay,
            allowancesTaxable: $allowances->taxable,
            allowancesNonTaxable: $allowances->nonTaxable,
            customDeductionsEmployee: $deductions->employee,
            customDeductionsEmployer: $deductions->employer,
            loanDeductions: $loans->total,
            adjustmentTaxableAdditions: $adjustments->taxableAdditions,
            adjustmentNonTaxableAdditions: $adjustments->nonTaxableAdditions,
            adjustmentDeductions: $adjustments->deductions,
            unpaidDaysReduction: $reduction->reduction,
            unpaidDaysCount: $reduction->unpaidDays,
            auditLines: $auditLines,
        );
    }
}
