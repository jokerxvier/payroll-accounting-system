<?php

declare(strict_types=1);

namespace App\Services\Statutory;

use App\ValueObjects\Money;

/**
 * Immutable result of a single statutory contribution computation.
 *
 * Carries the four amounts a payroll engine downstream may need:
 *   - $employeeShare:    deducted from the employee's net pay.
 *   - $employerShare:    paid by the employer alongside the employee share.
 *   - $employerEcShare:  Employees' Compensation premium, paid solely by the
 *                        employer. Non-zero only for SSS-style bands that
 *                        carry it; zero otherwise.
 *   - $taxableAmount:    the salary base that contributions were actually
 *                        computed against, after caps/floors were applied.
 *                        Useful for diagnostics and payslip notes ("computed
 *                        against MSC of P15,000.00"). May differ from the
 *                        input salary (PhilHealth ceiling, Pag-IBIG max MSC,
 *                        SSS band's contribution_base).
 *
 * All four fields are required so the shape is uniform across algorithms,
 * even those that don't naturally produce a non-zero EC share — they pass
 * `Money::zero()` rather than make the field nullable.
 */
final readonly class StatutoryContributionResult
{
    public function __construct(
        public Money $employeeShare,
        public Money $employerShare,
        public Money $employerEcShare,
        public Money $taxableAmount,
    ) {}

    /**
     * Convenience factory for the all-zero result.
     *
     * Useful as a sentinel when a contribution does not apply (e.g., salary
     * below an exemption threshold) without forcing every caller to construct
     * four `Money::zero()` instances by hand.
     */
    public static function zero(): self
    {
        return new self(
            employeeShare: Money::zero(),
            employerShare: Money::zero(),
            employerEcShare: Money::zero(),
            taxableAmount: Money::zero(),
        );
    }

    /**
     * Sum of the three contribution shares (employee + employer + employer EC).
     * Does NOT include `$taxableAmount`, which is a base, not a charge.
     */
    public function total(): Money
    {
        return $this->employeeShare
            ->plus($this->employerShare)
            ->plus($this->employerEcShare);
    }
}
