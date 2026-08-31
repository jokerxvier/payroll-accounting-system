<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Pas\ChartOfAccount;

/**
 * One account's line on the Trial Balance.
 *
 * Everything here is derived from two raw sums — debits and credits — and
 * the account's `normal_balance`. Two different signings are needed and
 * conflating them is the classic way a set of financial statements stops
 * agreeing with itself:
 *
 *  - **Raw** (`debits − credits`) drives the Dr/Cr columns. Because every
 *    posted entry balances, the raw sums across all accounts total zero,
 *    which is exactly why the two columns of a trial balance foot to the
 *    same figure. Signing these by normal balance first would break that.
 *  - **Natural** ({@see closingNaturalCentavos()}) states the balance in the
 *    direction the account increases, so a liability with a credit balance
 *    reads positive. This is what the Balance Sheet and Income Statement
 *    consume, and it goes through {@see ChartOfAccount::movementCentavos()}
 *    rather than restating the debit-normal formula.
 */
final readonly class TrialBalanceRow
{
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public string $type,
        public string $normalBalance,
        public int $openingDebitCentavos,
        public int $openingCreditCentavos,
        public int $periodDebitCentavos,
        public int $periodCreditCentavos,
    ) {}

    /** Opening balance as `debits − credits`, before any normal-balance signing. */
    public function openingRawCentavos(): int
    {
        return $this->openingDebitCentavos - $this->openingCreditCentavos;
    }

    /** Closing balance as `debits − credits`, before any normal-balance signing. */
    public function closingRawCentavos(): int
    {
        return $this->openingRawCentavos()
            + $this->periodDebitCentavos
            - $this->periodCreditCentavos;
    }

    /**
     * The closing balance stated in the account's own direction: positive
     * when a liability is in credit, positive when an asset is in debit.
     */
    public function closingNaturalCentavos(): int
    {
        return $this->naturalise($this->closingRawCentavos());
    }

    /** The opening balance stated in the account's own direction. */
    public function openingNaturalCentavos(): int
    {
        return $this->naturalise($this->openingRawCentavos());
    }

    /**
     * The movement inside the range, in the account's own direction.
     *
     * What an Income Statement consumes, and the distinction that matters
     * most to anything reporting a period: revenue earned *in these dates*,
     * not since the books opened. Reading `closingNaturalCentavos()` for an
     * income account under a one-month filter reports every peso the school
     * has ever earned, and reports it as this month's.
     */
    public function periodNaturalCentavos(): int
    {
        return $this->naturalise(
            $this->periodDebitCentavos - $this->periodCreditCentavos,
        );
    }

    /**
     * Raw (`debits − credits`) restated in the account's own direction.
     *
     * The rule lives here once rather than in each caller. It restates
     * {@see ChartOfAccount::movementCentavos()} rather than calling it: this
     * DTO holds the `normal_balance` string, not the model, so that a report
     * can be built without re-hydrating the chart.
     */
    private function naturalise(int $rawCentavos): int
    {
        return $this->normalBalance === ChartOfAccount::BALANCE_DEBIT
            ? $rawCentavos
            : -$rawCentavos;
    }

    /**
     * A balance of zero is printed in neither column rather than as `0.00`
     * in both — a trial balance with a zero in each column for the same
     * account reads as two offsetting facts instead of one absent one.
     */
    public function closingBalanceDebitCentavos(): int
    {
        return max($this->closingRawCentavos(), 0);
    }

    public function closingBalanceCreditCentavos(): int
    {
        return max(-$this->closingRawCentavos(), 0);
    }

    public function openingBalanceDebitCentavos(): int
    {
        return max($this->openingRawCentavos(), 0);
    }

    public function openingBalanceCreditCentavos(): int
    {
        return max(-$this->openingRawCentavos(), 0);
    }

    /**
     * Whether the account is worth printing: it either carried a balance
     * into the range or moved inside it. An account that has never been
     * posted to fails both and is dropped unless the caller asks for the
     * full chart.
     */
    public function isSignificant(): bool
    {
        return $this->openingRawCentavos() !== 0
            || $this->periodDebitCentavos !== 0
            || $this->periodCreditCentavos !== 0;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'normal_balance' => $this->normalBalance,
            'opening_debit_centavos' => $this->openingBalanceDebitCentavos(),
            'opening_credit_centavos' => $this->openingBalanceCreditCentavos(),
            'period_debit_centavos' => $this->periodDebitCentavos,
            'period_credit_centavos' => $this->periodCreditCentavos,
            'closing_debit_centavos' => $this->closingBalanceDebitCentavos(),
            'closing_credit_centavos' => $this->closingBalanceCreditCentavos(),
            'closing_natural_centavos' => $this->closingNaturalCentavos(),
        ];
    }
}
