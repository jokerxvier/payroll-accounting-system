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
        // Week 7 — allowances split.
        public Money $allowancesTaxable,
        public Money $allowancesNonTaxable,
        // Week 7 — custom deductions split by source.
        public Money $customDeductionsEmployee,
        public Money $customDeductionsEmployer,
        // Week 7 — loans (read-only preview at compute time).
        public Money $loanDeductions,
        // Week 7 — one-off adjustments split by kind × taxability.
        public Money $adjustmentTaxableAdditions,
        public Money $adjustmentNonTaxableAdditions,
        public Money $adjustmentDeductions,
        // Week 7 — unpaid leave reduction applied to basic pay.
        public Money $unpaidDaysReduction,
        public int $unpaidDaysCount,
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
            allowancesTaxable: $zero,
            allowancesNonTaxable: $zero,
            customDeductionsEmployee: $zero,
            customDeductionsEmployer: $zero,
            loanDeductions: $zero,
            adjustmentTaxableAdditions: $zero,
            adjustmentNonTaxableAdditions: $zero,
            adjustmentDeductions: $zero,
            unpaidDaysReduction: $zero,
            unpaidDaysCount: 0,
            auditLines: [],
        );
    }

    /**
     * Wire-shape serialisation for Inertia / JSON consumers.
     *
     * Every Money field is exposed as `{centavos: int, formatted: string}`
     * so the frontend never has to choose between precision (centavos) and
     * presentation (formatted). The integer is authoritative; the formatted
     * string is render-ready.
     *
     * The `period` block surfaces the cutoff window as ISO-8601 dates plus
     * the frequency tag — sufficient for the preview header to render
     * "May 1 – May 31, 2026 (Monthly)" without re-deriving anything.
     *
     * `auditLines` is the full ordered list of {@see PayrollLineItem}s, each
     * already in the canonical display order documented in
     * {@see PayrollComputationService::compute()}.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => [
                'frequency' => $this->period->frequency(),
                'start' => $this->period->start()->toDateString(),
                'end' => $this->period->end()->toDateString(),
                'daysInPeriod' => $this->period->daysInPeriod(),
            ],
            'basicPay' => $this->serializeMoney($this->basicPay),
            'grossPay' => $this->serializeMoney($this->grossPay),
            'sssEmployee' => $this->serializeMoney($this->sssEmployee),
            'sssEmployer' => $this->serializeMoney($this->sssEmployer),
            'sssEmployerEc' => $this->serializeMoney($this->sssEmployerEc),
            'philhealthEmployee' => $this->serializeMoney($this->philhealthEmployee),
            'philhealthEmployer' => $this->serializeMoney($this->philhealthEmployer),
            'pagibigEmployee' => $this->serializeMoney($this->pagibigEmployee),
            'pagibigEmployer' => $this->serializeMoney($this->pagibigEmployer),
            'birWithholdingTax' => $this->serializeMoney($this->birWithholdingTax),
            'totalEmployeeDeductions' => $this->serializeMoney($this->totalEmployeeDeductions),
            'totalEmployerContributions' => $this->serializeMoney($this->totalEmployerContributions),
            'taxableIncome' => $this->serializeMoney($this->taxableIncome),
            'netPay' => $this->serializeMoney($this->netPay),
            'allowancesTaxable' => $this->serializeMoney($this->allowancesTaxable),
            'allowancesNonTaxable' => $this->serializeMoney($this->allowancesNonTaxable),
            'customDeductionsEmployee' => $this->serializeMoney($this->customDeductionsEmployee),
            'customDeductionsEmployer' => $this->serializeMoney($this->customDeductionsEmployer),
            'loanDeductions' => $this->serializeMoney($this->loanDeductions),
            'adjustmentTaxableAdditions' => $this->serializeMoney($this->adjustmentTaxableAdditions),
            'adjustmentNonTaxableAdditions' => $this->serializeMoney($this->adjustmentNonTaxableAdditions),
            'adjustmentDeductions' => $this->serializeMoney($this->adjustmentDeductions),
            'unpaidDaysReduction' => $this->serializeMoney($this->unpaidDaysReduction),
            'unpaidDaysCount' => $this->unpaidDaysCount,
            'auditLines' => array_map(
                static fn (PayrollLineItem $line): array => $line->toArray(),
                $this->auditLines,
            ),
        ];
    }

    /**
     * @return array{centavos: int, formatted: string}
     */
    private function serializeMoney(Money $amount): array
    {
        return [
            'centavos' => $amount->centavos(),
            'formatted' => $amount->toDecimalString(),
        ];
    }
}
