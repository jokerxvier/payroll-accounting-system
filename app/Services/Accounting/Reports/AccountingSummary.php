<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonImmutable;

/**
 * The accounting dashboard's figures, all read from the posted ledger.
 *
 * Two kinds of number live here and they answer different questions:
 *
 *  - **Period movements** — income, expenses, net income, and the revenue
 *    breakdown. What happened *between these dates*.
 *  - **Point-in-time balances** — cash, receivables, payables. What the school
 *    holds and is owed *as at* the end of the range, carried opening balance
 *    included.
 *
 * Mixing them is the classic error: a "This Month" filter showing every peso
 * of tuition the school has ever billed, because closing was taken where
 * period was meant.
 *
 * @phpstan-type RevenueLine array{account_id: int, code: string, name: string, centavos: int}
 */
final readonly class AccountingSummary
{
    /**
     * @param  list<RevenueLine>  $revenueByAccount  Income accounts, largest first.
     */
    public function __construct(
        public ?CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $cashCentavos,
        public int $receivablesCentavos,
        public int $payablesCentavos,
        public int $incomeCentavos,
        public int $expensesCentavos,
        public array $revenueByAccount,
    ) {}

    /**
     * Income less expenses for the range.
     *
     * Derived rather than passed in: a stored net that disagreed with its two
     * halves would be a figure nobody could reconcile.
     */
    public function netIncomeCentavos(): int
    {
        return $this->incomeCentavos - $this->expensesCentavos;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from?->toDateString(),
            'to' => $this->to->toDateString(),
            'cash_centavos' => $this->cashCentavos,
            'receivables_centavos' => $this->receivablesCentavos,
            'payables_centavos' => $this->payablesCentavos,
            'income_centavos' => $this->incomeCentavos,
            'expenses_centavos' => $this->expensesCentavos,
            'net_income_centavos' => $this->netIncomeCentavos(),
            'revenue_by_account' => $this->revenueByAccount,
        ];
    }
}
