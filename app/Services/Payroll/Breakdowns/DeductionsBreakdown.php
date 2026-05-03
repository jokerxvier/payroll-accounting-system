<?php

declare(strict_types=1);

namespace App\Services\Payroll\Breakdowns;

use App\Actions\Payroll\ApplyEmployeeDeductions;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;

/**
 * Itemised result of {@see ApplyEmployeeDeductions}.
 *
 * Custom deductions split three ways:
 *
 *   - `employee`       — subtracted from net pay. Includes both
 *                        employee-only sources AND the employee half of
 *                        `both`-source deductions.
 *   - `employer`       — paid by the employer alongside payroll; never
 *                        affects net pay. Includes both employer-only
 *                        sources AND the employer half of `both`-source
 *                        deductions.
 *   - `taxableImpact`  — sum of the EMPLOYEE-borne amounts whose parent
 *                        `pas_deduction_types.is_taxable = true`. The
 *                        engine subtracts this from the BIR taxable base
 *                        (mirroring how statutory employee shares reduce
 *                        the base). Non-taxable deductions still reduce
 *                        net pay, but never the tax base.
 */
final readonly class DeductionsBreakdown
{
    /**
     * @param  list<PayrollLineItem>  $lines
     */
    public function __construct(
        public Money $employee,
        public Money $employer,
        public Money $taxableImpact,
        public array $lines,
    ) {}

    public static function zero(): self
    {
        return new self(
            employee: Money::zero(),
            employer: Money::zero(),
            taxableImpact: Money::zero(),
            lines: [],
        );
    }
}
