<?php

declare(strict_types=1);

namespace App\Services\Payroll\Breakdowns;

use App\Actions\Payroll\ApplyPayrollAdjustments;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;

/**
 * Itemised result of {@see ApplyPayrollAdjustments}.
 *
 * One-off adjustments split four ways by `kind` × `is_taxable`:
 *
 *   - `taxableAdditions`     — bonuses, back-pay, taxable allowances. The
 *                              engine folds these into gross pay BEFORE the
 *                              BIR taxable base is computed.
 *   - `nonTaxableAdditions`  — non-taxable reimbursements, one-off
 *                              non-taxable allowances. Added to net pay
 *                              only; never affects the tax base.
 *   - `deductions`           — ad-hoc deductions (cash advance recovery,
 *                              etc.). Subtracted from net pay; never
 *                              affects the tax base.
 *
 * The engine treats `taxable` deductions and `non-taxable` deductions
 * identically for this VO because adjustment deductions never reduce
 * taxable income — they're already-deducted-from-net amounts. If a future
 * "pre-tax cash advance recovery" feature is introduced, the engine adds a
 * fourth bucket here.
 */
final readonly class AdjustmentsBreakdown
{
    /**
     * @param  list<PayrollLineItem>  $lines
     */
    public function __construct(
        public Money $taxableAdditions,
        public Money $nonTaxableAdditions,
        public Money $deductions,
        public array $lines,
    ) {}

    public static function zero(): self
    {
        return new self(
            taxableAdditions: Money::zero(),
            nonTaxableAdditions: Money::zero(),
            deductions: Money::zero(),
            lines: [],
        );
    }
}
