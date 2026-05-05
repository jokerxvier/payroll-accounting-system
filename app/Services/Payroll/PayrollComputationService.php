<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Actions\Payroll\ApplyAllowances;
use App\Actions\Payroll\ApplyEmployeeDeductions;
use App\Actions\Payroll\ApplyEmployeeLoans;
use App\Actions\Payroll\ApplyPayrollAdjustments;
use App\Actions\Payroll\ApplyUnpaidDays;
use App\Actions\Payroll\ComputeBasicPay;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionRegistry;
use App\Services\Statutory\StatutoryContributionResolver;
use App\Services\Statutory\StatutoryContributionResult;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Composes the payroll computation actions and the statutory contribution
 * registry into a single {@see PayrollComputationResult} for one employee
 * against one pay period.
 *
 * This is the public entry point for the engine: callers (the batch persister
 * in §6F, the payslip preview controller, etc.) interact only with this
 * service. The underlying actions remain individually unit-testable but
 * are never invoked directly by feature code.
 *
 * Statutory contributions (SSS / PhilHealth / Pag-IBIG / BIR plus any future
 * municipal or scheme rows) are resolved from {@see StatutoryContributionRegistry}
 * — the engine no longer hard-codes the four PH codes. Each row carries its
 * own `application_order` (sequencing), `applies_to` (basis: gross pay vs
 * taxable income), and `algorithm` (strategy key). Adding a new contribution
 * is a row insert, not a code change.
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
 *      semi-monthly run the loop halves non-bracket-table results because
 *      `bracket_table` rows already carry per-period bracket arrays
 *      (BIR has explicit `monthly` and `semi_monthly` tables). Adding
 *      allowances or adjustments DOES NOT widen the statutory base either
 *      (decision 2 in the Week 7 plan).
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
 *   8. **Statutory loop** — registry rows are processed in
 *      `application_order` ASC. Each row's basis is selected by its
 *      `applies_to` column: `gross_pay` rows read from gross; `taxable_income`
 *      rows read from a running tally `(basicPay + taxableAllowances +
 *      taxableAdjustmentAdditions − accumulatedEmployeeStatutoryDeductions −
 *      customTaxableEmployeeDeductions)`. The PH set seeds with order
 *      10 (SSS) / 20 (PhilHealth) / 30 (Pag-IBIG) / 90 (BIR), so all three
 *      gross-based contributions land in the running tally before BIR
 *      reads its taxable-income basis.
 *
 *   9. **Net pay** — `gross − totalEmployeeDeductions`. Because gross now
 *      includes non-taxable earnings, the engine no longer adds them
 *      separately to net.
 *
 *  10. **Audit lines** — the engine emits one {@see PayrollLineItem} per
 *      discrete contribution line, in a canonical display order: basic pay
 *      first, then per registry row in order (employee share, then any
 *      employer + employer-EC shares the strategy returned), then Week-7
 *      additions in this order: allowances (taxable then non-taxable),
 *      adjustments (additions then deductions), custom deductions, loans,
 *      and finally the unpaid-days line if any.
 *
 * The service is stateless. The actions and resolver are constructor-injected
 * via Laravel's auto-wiring — no service-provider binding required.
 */
final class PayrollComputationService
{
    public function __construct(
        private ComputeBasicPay $computeBasicPay,
        private ApplyAllowances $applyAllowances,
        private ApplyEmployeeDeductions $applyEmployeeDeductions,
        private ApplyEmployeeLoans $applyEmployeeLoans,
        private ApplyPayrollAdjustments $applyPayrollAdjustments,
        private ApplyUnpaidDays $applyUnpaidDays,
        private StatutoryContributionRegistry $registry,
        private StatutoryContributionResolver $resolver,
    ) {}

    public function compute(EmployeeProfile $profile, PayPeriodInput $period): PayrollComputationResult
    {
        // 1. Basic pay handles inactive / out-of-period / pro-ration.
        $basicPay = ($this->computeBasicPay)($profile, $period);

        // 2. Short-circuit when there's nothing to compute. Skipping the
        //    statutory registry here is what lets a fresh DB (no contribution
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
        //    statutory / etc. lookups are skipped just like the
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

        // 9. Statutory loop. The registry returns rows in `application_order`
        //    ASC; we process them in that order so any `taxable_income` rows
        //    (e.g. BIR at order 90) read a running tally that already
        //    subtracted the earlier `gross_pay` rows' employee shares.
        $contributions = $this->registry->active($period->end());

        /** @var array<string, StatutoryContributionResult> $contributionResults */
        $contributionResults = [];
        $accumulatedEmployeeStatutoryShares = Money::zero();

        foreach ($contributions as $contribution) {
            // Per-employee opt-out: zero the result so legacy DTO mapping
            // still works, and don't grow the statutory-deduction running
            // tally — exempt employees correctly see a higher BIR taxable
            // basis because no SSS/PhilHealth/etc. share reduces it.
            if ($profile->isExemptFrom($contribution->contribution_code)) {
                $contributionResults[$contribution->contribution_code] = StatutoryContributionResult::zero();

                continue;
            }

            $basis = match ($contribution->applies_to) {
                StatutoryContribution::APPLIES_TO_GROSS_PAY => $monthlyBasis,
                StatutoryContribution::APPLIES_TO_TAXABLE_INCOME => $basicPay
                    ->plus($allowances->taxable)
                    ->plus($adjustments->taxableAdditions)
                    ->minus($accumulatedEmployeeStatutoryShares)
                    ->minus($deductions->taxableImpact),
                default => $monthlyBasis,
            };

            $context = $contribution->algorithm === StatutoryContribution::ALGORITHM_BRACKET_TABLE
                ? ['period_type' => $period->frequency()]
                : [];

            $result = $this->resolver->compute(
                $contribution->contribution_code,
                $basis,
                $period->end(),
                $context,
            );

            // Halve only when the strategy isn't already period-aware.
            // `bracket_table` rows ship with explicit per-period bracket
            // arrays so the strategy returns the per-period figure already;
            // every other algorithm computes against the full monthly basis
            // and needs to be halved for semi-monthly runs.
            if ($period->isSemiMonthly() && $contribution->algorithm !== StatutoryContribution::ALGORITHM_BRACKET_TABLE) {
                $result = new StatutoryContributionResult(
                    employeeShare: $result->employeeShare->dividedBy(2),
                    employerShare: $result->employerShare->dividedBy(2),
                    employerEcShare: $result->employerEcShare->dividedBy(2),
                    taxableAmount: $result->taxableAmount->dividedBy(2),
                );
            }

            $contributionResults[$contribution->contribution_code] = $result;
            $accumulatedEmployeeStatutoryShares = $accumulatedEmployeeStatutoryShares
                ->plus($result->employeeShare);
        }

        // 10. Map known PH codes onto the legacy DTO fields so existing
        //     consumers (frontend types, batch persister, payslips) keep
        //     reading the same shape. Unknown codes still flow through
        //     `totalEmployeeDeductions` / `totalEmployerContributions` and
        //     show up as audit lines.
        $sssResult = $contributionResults[StatutoryContribution::CODE_SSS] ?? StatutoryContributionResult::zero();
        $philhealthResult = $contributionResults[StatutoryContribution::CODE_PHILHEALTH] ?? StatutoryContributionResult::zero();
        $pagibigResult = $contributionResults[StatutoryContribution::CODE_PAGIBIG] ?? StatutoryContributionResult::zero();
        $birResult = $contributionResults[StatutoryContribution::CODE_BIR] ?? StatutoryContributionResult::zero();

        // The taxable income surfaced on the DTO is the BIR-flavour basis: the
        // post-statutory taxable income BIR was computed against. When BIR is
        // not seeded we fall back to the same formula so the field stays
        // meaningful for callers (payslip preview, etc.).
        $taxableIncome = $contributions->firstWhere('contribution_code', StatutoryContribution::CODE_BIR) !== null
            ? $birResult->taxableAmount
            : $basicPay
                ->plus($allowances->taxable)
                ->plus($adjustments->taxableAdditions)
                ->minus($accumulatedEmployeeStatutoryShares)
                ->minus($deductions->taxableImpact);

        // 11. Aggregate employee deductions: every contribution's employee
        //     share + custom deductions + loans + adjustment deductions.
        //     `$accumulatedEmployeeStatutoryShares` was already accumulated
        //     by the statutory loop above; re-use it instead of summing
        //     again.
        $totalEmployerContributionShares = Money::zero();
        foreach ($contributionResults as $result) {
            $totalEmployerContributionShares = $totalEmployerContributionShares
                ->plus($result->employerShare)
                ->plus($result->employerEcShare);
        }

        $totalEmployeeDeductions = $accumulatedEmployeeStatutoryShares
            ->plus($deductions->employee)
            ->plus($loans->total)
            ->plus($adjustments->deductions);

        $totalEmployerContributions = $totalEmployerContributionShares
            ->plus($deductions->employer);

        // 12. Net pay = gross − total employee deductions. Non-taxable items
        //     are already in gross, so no separate "add to net" step.
        $netPay = $grossPay->minus($totalEmployeeDeductions);

        // 13. Canonical audit-line order: basic, then employee statutory
        //     shares (registry order), then employer + EC shares (registry
        //     order), then Week-7 additions in deterministic order.
        $auditLines = [
            new PayrollLineItem(
                code: PayrollLineItem::CODE_BASIC_PAY,
                label: 'Basic pay',
                amount: $basicPay,
                bucket: PayrollLineItem::BUCKET_EARNING,
                meta: null,
            ),
        ];

        // Employee shares first, in registry order.
        foreach ($contributions as $contribution) {
            if ($profile->isExemptFrom($contribution->contribution_code)) {
                continue;
            }

            $result = $contributionResults[$contribution->contribution_code];
            $meta = $contribution->applies_to === StatutoryContribution::APPLIES_TO_TAXABLE_INCOME
                ? ['taxable_income_centavos' => $result->taxableAmount->centavos()]
                : ['contribution_base_centavos' => $result->taxableAmount->centavos()];

            $auditLines[] = new PayrollLineItem(
                code: $contribution->contribution_code.'_EMPLOYEE',
                label: $contribution->label.' (employee)',
                amount: $result->employeeShare,
                bucket: PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                meta: $meta,
            );
        }

        // Then employer shares (and any EC) in registry order.
        foreach ($contributions as $contribution) {
            if ($profile->isExemptFrom($contribution->contribution_code)) {
                continue;
            }

            $result = $contributionResults[$contribution->contribution_code];

            // Skip emitting an employer line when both employer shares are
            // zero — strategies like BracketTableStrategy carry no employer
            // share at all (BIR), so suppressing zero rows keeps payslips
            // free of noise lines.
            if (! $result->employerShare->isZero()) {
                $auditLines[] = new PayrollLineItem(
                    code: $contribution->contribution_code.'_EMPLOYER',
                    label: $contribution->label.' (employer)',
                    amount: $result->employerShare,
                    bucket: PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
                );
            }

            if (! $result->employerEcShare->isZero()) {
                $auditLines[] = new PayrollLineItem(
                    code: $contribution->contribution_code.'_EMPLOYER_EC',
                    label: $contribution->label." Employees' Compensation (employer)",
                    amount: $result->employerEcShare,
                    bucket: PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
                );
            }
        }

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
            sssEmployee: $sssResult->employeeShare,
            sssEmployer: $sssResult->employerShare,
            sssEmployerEc: $sssResult->employerEcShare,
            philhealthEmployee: $philhealthResult->employeeShare,
            philhealthEmployer: $philhealthResult->employerShare,
            pagibigEmployee: $pagibigResult->employeeShare,
            pagibigEmployer: $pagibigResult->employerShare,
            birWithholdingTax: $birResult->employeeShare,
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
