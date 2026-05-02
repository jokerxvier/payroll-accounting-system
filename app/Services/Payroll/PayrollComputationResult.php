<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Pas\EmployeeProfile;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Immutable, fully-itemised result of running the payroll computation engine
 * for ONE employee against ONE pay period.
 *
 * Aggregate Money fields are convenience views; the authoritative breakdown is
 * `$auditLines` (a list of {@see PayrollLineItem}). Totals are derived from
 * lines, not the other way around — the engine owns the reconciliation.
 *
 * This DTO is a pure data carrier: no behaviour, no DB access, no Eloquent
 * persistence. The payroll batch persister (Section 6F) is the only caller
 * permitted to write these fields back to `pas_payslips` etc.
 *
 * @phpstan-type PayrollAuditLines list<PayrollLineItem>
 */
final readonly class PayrollComputationResult
{
    /**
     * @param  list<PayrollLineItem>  $auditLines
     */
    public function __construct(
        public EmployeeProfile $employee,
        public PayPeriodInput $period,
        public Money $basicPay,
        public Money $grossPay,
        public Money $sssEmployee,
        public Money $sssEmployer,
        public Money $sssEmployerEc,
        public Money $philhealthEmployee,
        public Money $philhealthEmployer,
        public Money $pagibigEmployee,
        public Money $pagibigEmployer,
        public Money $birWithholdingTax,
        public Money $totalEmployeeDeductions,
        public Money $totalEmployerContributions,
        public Money $taxableIncome,
        public Money $netPay,
        public array $auditLines,
    ) {}

    /**
     * Build an all-zero result for the given employee and period.
     *
     * Used by the engine when an employee is inactive, on unpaid leave for the
     * entire period, or otherwise has no pay to compute. Returning a zero
     * result rather than throwing keeps the upstream batch flow uniform —
     * every active employee always produces a `PayrollComputationResult`.
     */
    public static function zero(EmployeeProfile $employee, PayPeriodInput $period): self
    {
        $zero = Money::zero();

        return new self(
            employee: $employee,
            period: $period,
            basicPay: $zero,
            grossPay: $zero,
            sssEmployee: $zero,
            sssEmployer: $zero,
            sssEmployerEc: $zero,
            philhealthEmployee: $zero,
            philhealthEmployer: $zero,
            pagibigEmployee: $zero,
            pagibigEmployer: $zero,
            birWithholdingTax: $zero,
            totalEmployeeDeductions: $zero,
            totalEmployerContributions: $zero,
            taxableIncome: $zero,
            netPay: $zero,
            auditLines: [],
        );
    }
}
