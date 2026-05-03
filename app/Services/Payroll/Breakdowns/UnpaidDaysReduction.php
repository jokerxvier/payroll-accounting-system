<?php

declare(strict_types=1);

namespace App\Services\Payroll\Breakdowns;

use App\Actions\Payroll\ApplyUnpaidDays;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;

/**
 * Itemised result of {@see ApplyUnpaidDays}.
 *
 * The engine subtracts `reduction` from basic pay before any other
 * computation sees `basicPay`. `unpaidDays` is the integer day count used
 * to derive the reduction (kept for payslip diagnostics). `line` is null
 * when there are no unpaid days — the engine omits the audit row entirely
 * in that case so payslips for fully-worked periods don't carry a noise
 * `unpaid_days: 0.00` row.
 */
final readonly class UnpaidDaysReduction
{
    public function __construct(
        public Money $reduction,
        public int $unpaidDays,
        public ?PayrollLineItem $line,
    ) {}

    public static function zero(): self
    {
        return new self(
            reduction: Money::zero(),
            unpaidDays: 0,
            line: null,
        );
    }
}
