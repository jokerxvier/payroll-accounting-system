<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Actions\Payroll\ComputeBasicPay;
use App\Actions\Payroll\ComputeBirWithholdingTax;
use App\Actions\Payroll\ComputePagibigContribution;
use App\Actions\Payroll\ComputePhilhealthContribution;
use App\Actions\Payroll\ComputeSssContribution;
use App\Models\Pas\EmployeeProfile;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Composes the five payroll computation actions into a single
 * {@see PayrollComputationResult} for one employee against one pay period.
 *
 * This is the public entry point for the engine: callers (the batch persister
 * in §6F, the payslip preview controller, etc.) interact only with this
 * service. The five underlying actions remain individually unit-testable but
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
 *   2. **Statutory contribution basis** — every contribution is computed
 *      against the FULL monthly salary, not against `basicPay`. Regulators
 *      (SSS, PhilHealth, Pag-IBIG) define their tables monthly; for a
 *      semi-monthly run the four contribution actions internally halve the
 *      result. The basis for a partial month (newly hired employee, etc.) is
 *      still the full monthly salary — the regulators do not pro-rate
 *      contribution bases by tenure.
 *
 *   3. **Gross pay** — for Week 6 this is identical to `basicPay`. Week 7
 *      will widen this to include allowances, overtime, and one-time
 *      adjustments by folding them into `grossPay` here.
 *
 *   4. **Taxable income** — `gross - employee statutory deductions`. BIR
 *      withholding tax is then computed against this taxable income, NOT
 *      against gross. This is the canonical TRAIN-style flow: the employee
 *      shares of SSS/PhilHealth/Pag-IBIG are non-taxable.
 *
 *   5. **Net pay** — `gross - (sum of all employee deductions, including BIR)`.
 *      Employer contributions and the EC premium do not affect net pay.
 *
 *   6. **Audit lines** — the engine emits nine {@see PayrollLineItem}s in a
 *      canonical display order (basic, employee deductions in
 *      SSS/PhilHealth/Pag-IBIG/BIR order, then employer contributions in the
 *      same order with EC immediately after the SSS employer share). Each
 *      deduction line carries `meta.contribution_base_centavos` for payslip
 *      diagnostics; the BIR line carries `meta.taxable_income_centavos`. The
 *      basic line carries `meta = null` because there is no derived basis to
 *      surface.
 *
 * The service is stateless. The five actions are constructor-injected via
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

        // 3. Statutory contribution basis is the FULL monthly salary,
        //    regardless of pay frequency or partial-tenure pro-ration.
        $monthlyBasis = Money::fromCentavos($profile->basic_salary_centavos);

        $sss = ($this->computeSss)($monthlyBasis, $period);
        $philhealth = ($this->computePhilhealth)($monthlyBasis, $period);
        $pagibig = ($this->computePagibig)($monthlyBasis, $period);

        // 4. Week 6 has no allowances yet, so gross == basic. Week 7 widens
        //    this to fold in earnings beyond basic pay before tax math.
        $grossPay = $basicPay;

        // 5. Taxable income = gross − employee statutory deductions. BIR is
        //    applied against THIS, not against gross.
        $taxableIncome = $grossPay
            ->minus($sss->employeeShare)
            ->minus($philhealth->employeeShare)
            ->minus($pagibig->employeeShare);

        $birTax = ($this->computeBir)($taxableIncome, $period);

        // 6. Aggregates derive from the per-strategy shares.
        $totalEmployeeDeductions = $sss->employeeShare
            ->plus($philhealth->employeeShare)
            ->plus($pagibig->employeeShare)
            ->plus($birTax);

        $totalEmployerContributions = $sss->employerShare
            ->plus($sss->employerEcShare)
            ->plus($philhealth->employerShare)
            ->plus($pagibig->employerShare);

        $netPay = $grossPay->minus($totalEmployeeDeductions);

        // 7. Canonical audit-line order: earnings first, then employee
        //    deductions (SSS, PhilHealth, Pag-IBIG, BIR), then employer
        //    contributions (SSS share, SSS EC, PhilHealth, Pag-IBIG).
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
            auditLines: $auditLines,
        );
    }
}
