<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use App\Services\Payroll\Breakdowns\AdjustmentsBreakdown;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Resolve the one-off payroll adjustments that fire on a single pay period
 * and split them by `kind` × `is_taxable`.
 *
 * Algorithm:
 *
 *   1. Load every {@see PayrollAdjustment} that
 *      a) belongs to the given profile, and
 *      b) has an `applies_on` date inside `[period.start(), period.end()]`
 *         — both ends inclusive (an adjustment dated exactly on the
 *         period's start day OR exactly on its end day belongs to that
 *         period; the day before / after is excluded). Boundary semantics
 *         live in `PayrollAdjustment::scopeForPeriod`.
 *
 *   2. Bucket by `kind` × `is_taxable`:
 *        - addition × taxable      → `taxableAdditions`     (folds into BIR base).
 *        - addition × non-taxable  → `nonTaxableAdditions`  (net pay only).
 *        - deduction × any         → `deductions`           (net pay only;
 *                                     does NOT reduce the BIR base — see
 *                                     `AdjustmentsBreakdown` docblock).
 *
 *   3. Emit one {@see PayrollLineItem} per adjustment with code
 *      `CODE_ADJUSTMENT_ADDITION` (additions) or `CODE_ADJUSTMENT_DEDUCTION`
 *      (deductions), bucket `BUCKET_EARNING` for additions and
 *      `BUCKET_EMPLOYEE_DEDUCTION` for deductions. The label is the
 *      adjustment's user-supplied `label`; `meta` carries the
 *      `payroll_adjustment_id`, `is_taxable` flag, and the `applies_on`
 *      date (ISO 8601 string) for audit.
 *
 * The action is stateless and side-effect-free. It reads from MySQL but
 * never writes — `applied_pay_period_id` backfill happens in Week 9 from
 * the batch persistence path, not here.
 */
final class ApplyPayrollAdjustments
{
    public function __invoke(EmployeeProfile $profile, PayPeriodInput $period): AdjustmentsBreakdown
    {
        /** @var list<PayrollAdjustment> $adjustments */
        $adjustments = PayrollAdjustment::query()
            ->where('employee_profile_id', $profile->id)
            ->forPeriod($period)
            ->get()
            ->all();

        if ($adjustments === []) {
            return AdjustmentsBreakdown::zero();
        }

        $taxableAdditions = Money::zero();
        $nonTaxableAdditions = Money::zero();
        $deductions = Money::zero();
        $lines = [];

        foreach ($adjustments as $adjustment) {
            $amount = Money::fromCentavos($adjustment->amount_centavos);

            if ($adjustment->kind === PayrollAdjustment::KIND_ADDITION) {
                if ($adjustment->is_taxable) {
                    $taxableAdditions = $taxableAdditions->plus($amount);
                } else {
                    $nonTaxableAdditions = $nonTaxableAdditions->plus($amount);
                }

                $code = PayrollLineItem::CODE_ADJUSTMENT_ADDITION;
                $bucket = PayrollLineItem::BUCKET_EARNING;
            } else {
                $deductions = $deductions->plus($amount);

                $code = PayrollLineItem::CODE_ADJUSTMENT_DEDUCTION;
                $bucket = PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION;
            }

            $lines[] = new PayrollLineItem(
                code: $code,
                label: $adjustment->label,
                amount: $amount,
                bucket: $bucket,
                meta: [
                    'payroll_adjustment_id' => $adjustment->id,
                    'is_taxable' => $adjustment->is_taxable,
                    'applies_on' => $adjustment->applies_on->toDateString(),
                ],
            );
        }

        return new AdjustmentsBreakdown(
            taxableAdditions: $taxableAdditions,
            nonTaxableAdditions: $nonTaxableAdditions,
            deductions: $deductions,
            lines: $lines,
        );
    }
}
