<?php

declare(strict_types=1);

namespace App\Services\Payroll\Breakdowns;

use App\Actions\Payroll\ApplyAllowances;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;

/**
 * Itemised result of {@see ApplyAllowances}.
 *
 * Allowances split into two buckets at compute time:
 *
 *   - `taxable`     — folds into the BIR taxable base (after subtracting
 *                     statutory employee shares and custom taxable
 *                     deductions). The engine adds it to gross pay.
 *   - `nonTaxable`  — adds to net pay only; never affects the BIR base.
 *
 * The `lines` collection mirrors what eventually appears on the payslip:
 * one {@see PayrollLineItem} per allowance subscription that fired, with
 * the `meta.allowance_id` cross-reference for the audit trail.
 */
final readonly class AllowancesBreakdown
{
    /**
     * @param  list<PayrollLineItem>  $lines
     */
    public function __construct(
        public Money $taxable,
        public Money $nonTaxable,
        public array $lines,
    ) {}

    /**
     * Canonical empty result — no active subscriptions, no lines, both
     * buckets zero. Used by short-circuits and by callers that need a
     * default to merge into.
     */
    public static function zero(): self
    {
        return new self(
            taxable: Money::zero(),
            nonTaxable: Money::zero(),
            lines: [],
        );
    }
}
