<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use App\Services\Payroll\Breakdowns\AllowancesBreakdown;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Resolve the allowance subscriptions that fire on a single pay period and
 * split their amounts into the taxable / non-taxable buckets the engine
 * needs.
 *
 * Algorithm:
 *
 *   1. Load every {@see EmployeeAllowance} that
 *      a) belongs to the given profile,
 *      b) has an effective range covering `period.end()` (the canonical
 *         lookup date — same convention as `StatutoryContribution`), and
 *      c) has a `schedule` value that fires on this period (matrix in
 *         {@see EmployeeDeduction::schedulesMatching()}).
 *      Eager-load the parent `pas_allowances` row so the bucketing logic
 *      can read `is_taxable`, `is_de_minimis`, and `de_minimis_cap_centavos`
 *      without an N+1.
 *
 *   2. For each subscription compute the effective amount:
 *        - Start from `subscription.amount_centavos`.
 *        - If the parent allowance is de-minimis AND the cap is non-null,
 *          clamp the amount to the cap. This is a per-period, per-line
 *          clamp; the allowance excess does NOT spill into a "taxable
 *          excess" line in v1 (decision 5 in the Week 7 plan — ship the
 *          column, defer the cap UX). Any excess is silently dropped at
 *          this layer; the admin UI will surface configured caps directly.
 *
 *   3. Bucket by `parent.is_taxable`:
 *        - is_taxable=true   → folds into BIR taxable base via
 *                              `AllowancesBreakdown.taxable`.
 *        - is_taxable=false  → adds to net pay only via
 *                              `AllowancesBreakdown.nonTaxable`.
 *
 *   4. Emit one {@see PayrollLineItem} per subscription with the
 *      bucket-appropriate code (`CODE_ALLOWANCE_TAXABLE` /
 *      `CODE_ALLOWANCE_NON_TAXABLE`). The label is the allowance's
 *      human-readable `name`; `meta.allowance_id` carries the FK back to
 *      the catalog row for the audit trail.
 *
 * The action is stateless and side-effect-free — it reads from MySQL but
 * never writes. Safe to invoke concurrently across employees in a batch.
 */
final class ApplyAllowances
{
    public function __invoke(EmployeeProfile $profile, PayPeriodInput $period): AllowancesBreakdown
    {
        /** @var list<EmployeeAllowance> $subscriptions */
        $subscriptions = EmployeeAllowance::query()
            ->where('employee_profile_id', $profile->id)
            ->activeOn($period->end())
            ->forSchedule($period)
            ->with('allowance')
            ->get()
            ->all();

        if ($subscriptions === []) {
            return AllowancesBreakdown::zero();
        }

        $taxable = Money::zero();
        $nonTaxable = Money::zero();
        $lines = [];

        foreach ($subscriptions as $subscription) {
            $allowance = $subscription->allowance;

            $amount = Money::fromCentavos($subscription->amount_centavos);

            // De-minimis cap: clamp the per-period amount to the configured
            // cap when both the flag and the cap are present. NULL cap means
            // "not yet configured" (Q3 in PLAN.md) — pass through unchanged.
            if ($allowance->is_de_minimis && $allowance->de_minimis_cap_centavos !== null) {
                $cap = Money::fromCentavos($allowance->de_minimis_cap_centavos);
                if ($amount->greaterThan($cap)) {
                    $amount = $cap;
                }
            }

            if ($allowance->is_taxable) {
                $taxable = $taxable->plus($amount);
                $code = PayrollLineItem::CODE_ALLOWANCE_TAXABLE;
            } else {
                $nonTaxable = $nonTaxable->plus($amount);
                $code = PayrollLineItem::CODE_ALLOWANCE_NON_TAXABLE;
            }

            $lines[] = new PayrollLineItem(
                code: $code,
                label: $allowance->name,
                amount: $amount,
                bucket: PayrollLineItem::BUCKET_EARNING,
                meta: ['allowance_id' => $allowance->id],
            );
        }

        return new AllowancesBreakdown(
            taxable: $taxable,
            nonTaxable: $nonTaxable,
            lines: $lines,
        );
    }
}
