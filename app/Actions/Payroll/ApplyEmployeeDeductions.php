<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use App\Services\Payroll\Breakdowns\DeductionsBreakdown;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Resolve the custom (non-statutory) deduction subscriptions that fire on
 * a single pay period and split them by source × taxability.
 *
 * Algorithm:
 *
 *   1. Load every {@see EmployeeDeduction} that
 *      a) belongs to the given profile,
 *      b) has an effective range covering `period.end()`, and
 *      c) has a `schedule` value that fires on this period.
 *      Eager-load the parent `pas_deduction_types` row so the bucketing
 *      logic can read `is_taxable`, `calc_method`, and `source` without
 *      an N+1.
 *
 *   2. For each subscription compute the amount based on `calc_method`:
 *        - `fixed`   → `subscription.amount_centavos`.
 *        - `percent` → `grossForPercent.times(percent_basis_points).dividedBy(10000)`
 *                      Banker's rounding via `Money::dividedBy()`. Basis
 *                      points: 250 = 2.50%, 1_000 = 10.00%. The denominator
 *                      is 10_000 because BPs are 1/100 of a percent.
 *
 *   3. Bucket by parent `source`:
 *        - `employee` → contributes to employee bucket only.
 *        - `employer` → contributes to employer bucket only.
 *        - `both`     → SAME amount contributes to BOTH buckets. This
 *                       mirrors how a "shared" deduction (e.g., a 50/50
 *                       insurance premium) costs the employee a peso AND
 *                       costs the employer a peso. The `both` source does
 *                       NOT split the amount in half — that responsibility
 *                       lives with the catalog (define two rows, one per
 *                       source) if the company wants asymmetric splits.
 *
 *   4. Track `taxableImpact`: only the EMPLOYEE-borne amount of taxable
 *      deductions reduces the BIR taxable base downstream. Non-taxable
 *      employee deductions (HMO, etc.) reduce net pay but never the tax
 *      base. Employer-borne amounts never affect the tax base regardless
 *      of `is_taxable` because they don't reduce the employee's gross.
 *
 *   5. Emit one {@see PayrollLineItem} per subscription with code
 *      {@see PayrollLineItem::CODE_CUSTOM_DEDUCTION}. The bucket is
 *      `BUCKET_EMPLOYEE_DEDUCTION` for employee-only / both-source rows
 *      and `BUCKET_EMPLOYER_CONTRIBUTION` for employer-only rows. For
 *      `both`-source we emit ONE line in the employee bucket carrying the
 *      employee-side amount; the employer-side amount is rolled into the
 *      `employer` aggregate and not surfaced as a separate audit line in
 *      v1 (the engine's payslip-narrative invariant is "one DB row → one
 *      payslip line"). A future v2 may split into two lines if the client
 *      asks for symmetric employer-cost reporting.
 *
 * `$grossForPercent` is the basis used for `percent` calculations — the
 * caller decides what counts as gross (basic + taxable allowances +
 * taxable adjustment additions per the engine's compute order). For
 * `fixed` rows the parameter is ignored.
 *
 * The action is stateless and side-effect-free.
 */
final class ApplyEmployeeDeductions
{
    public function __invoke(
        EmployeeProfile $profile,
        PayPeriodInput $period,
        Money $grossForPercent,
    ): DeductionsBreakdown {
        /** @var list<EmployeeDeduction> $subscriptions */
        $subscriptions = EmployeeDeduction::query()
            ->where('employee_profile_id', $profile->id)
            ->activeOn($period->end())
            ->forSchedule($period)
            ->with('deductionType')
            ->get()
            ->all();

        if ($subscriptions === []) {
            return DeductionsBreakdown::zero();
        }

        $employee = Money::zero();
        $employer = Money::zero();
        $taxableImpact = Money::zero();
        $lines = [];

        foreach ($subscriptions as $subscription) {
            $type = $subscription->deductionType;

            $amount = $this->computeAmount($subscription, $type, $grossForPercent);

            $hitsEmployee = $type->source === DeductionType::SOURCE_EMPLOYEE
                || $type->source === DeductionType::SOURCE_BOTH;

            $hitsEmployer = $type->source === DeductionType::SOURCE_EMPLOYER
                || $type->source === DeductionType::SOURCE_BOTH;

            if ($hitsEmployee) {
                $employee = $employee->plus($amount);

                if ($type->is_taxable) {
                    $taxableImpact = $taxableImpact->plus($amount);
                }
            }

            if ($hitsEmployer) {
                $employer = $employer->plus($amount);
            }

            // The audit line is emitted against the more user-visible
            // bucket: employee if the cost touches the worker, employer
            // otherwise. This keeps payslip narratives clean — the only
            // payslip-visible deductions are those the employee actually
            // pays. Pure employer-borne lines still appear so the payroll
            // batch persister can post them as employer cost rows.
            $bucket = $hitsEmployee
                ? PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION
                : PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION;

            $lines[] = new PayrollLineItem(
                code: PayrollLineItem::CODE_CUSTOM_DEDUCTION,
                label: $type->name,
                amount: $amount,
                bucket: $bucket,
                meta: [
                    'deduction_type_id' => $type->id,
                    'source' => $type->source,
                    'is_taxable' => $type->is_taxable,
                ],
            );
        }

        return new DeductionsBreakdown(
            employee: $employee,
            employer: $employer,
            taxableImpact: $taxableImpact,
            lines: $lines,
        );
    }

    private function computeAmount(
        EmployeeDeduction $subscription,
        DeductionType $type,
        Money $grossForPercent,
    ): Money {
        if ($type->calc_method === DeductionType::CALC_PERCENT) {
            $bps = $subscription->percent_basis_points ?? 0;

            // grossForPercent × bps / 10_000. Banker's rounding via
            // Money::dividedBy keeps the math integer-only and
            // statistically unbiased for repeated accumulation.
            return $grossForPercent->times($bps)->dividedBy(10_000);
        }

        return Money::fromCentavos($subscription->amount_centavos ?? 0);
    }
}
