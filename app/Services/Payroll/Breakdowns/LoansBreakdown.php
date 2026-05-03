<?php

declare(strict_types=1);

namespace App\Services\Payroll\Breakdowns;

use App\Actions\Payroll\ApplyEmployeeLoans;
use App\Models\Pas\EmployeeLoan;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;

/**
 * Itemised result of {@see ApplyEmployeeLoans}.
 *
 * Compute-time view: every open loan that fires on this period contributes
 * `min(monthly_amortization, outstanding_balance)` to `total`, which the
 * engine subtracts from net pay. The action is read-only; the actual
 * decrement of `outstanding_balance_centavos` happens in the Week-9 batch
 * persistence path via {@see EmployeeLoan::applyAmortization()}.
 */
final readonly class LoansBreakdown
{
    /**
     * @param  list<PayrollLineItem>  $lines
     */
    public function __construct(
        public Money $total,
        public array $lines,
    ) {}

    public static function zero(): self
    {
        return new self(
            total: Money::zero(),
            lines: [],
        );
    }
}
