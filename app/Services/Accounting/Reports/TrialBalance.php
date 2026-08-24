<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Actions\Accounting\PostJournalEntry;
use Carbon\CarbonImmutable;

/**
 * A Trial Balance: every account's opening balance, movement across the
 * range, and closing balance, with the column totals that prove the ledger
 * holds together.
 *
 * {@see isBalanced()} is the whole point of the report. Debits equal credits
 * on every individual entry — {@see PostJournalEntry}
 * refuses otherwise — so the columns here can only disagree if something got
 * into `pas_journal_entry_lines` without going through posting. The report
 * surfaces that rather than quietly printing it, and the page shows the
 * result as a pass/fail line instead of leaving the reader to add up six
 * columns by hand.
 */
final readonly class TrialBalance
{
    /** @param list<TrialBalanceRow> $rows */
    public function __construct(
        public array $rows,
        public ?CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public function totalOpeningDebitCentavos(): int
    {
        return $this->sum(fn (TrialBalanceRow $row): int => $row->openingBalanceDebitCentavos());
    }

    public function totalOpeningCreditCentavos(): int
    {
        return $this->sum(fn (TrialBalanceRow $row): int => $row->openingBalanceCreditCentavos());
    }

    public function totalPeriodDebitCentavos(): int
    {
        return $this->sum(fn (TrialBalanceRow $row): int => $row->periodDebitCentavos);
    }

    public function totalPeriodCreditCentavos(): int
    {
        return $this->sum(fn (TrialBalanceRow $row): int => $row->periodCreditCentavos);
    }

    public function totalClosingDebitCentavos(): int
    {
        return $this->sum(fn (TrialBalanceRow $row): int => $row->closingBalanceDebitCentavos());
    }

    public function totalClosingCreditCentavos(): int
    {
        return $this->sum(fn (TrialBalanceRow $row): int => $row->closingBalanceCreditCentavos());
    }

    /**
     * Whether all three column pairs foot to the same figure.
     *
     * Checks opening and movement as well as closing: a closing pair can
     * agree while the two halves that produced it disagree in equal and
     * opposite ways, which would hide the very defect the report exists to
     * find.
     */
    public function isBalanced(): bool
    {
        return $this->totalOpeningDebitCentavos() === $this->totalOpeningCreditCentavos()
            && $this->totalPeriodDebitCentavos() === $this->totalPeriodCreditCentavos()
            && $this->totalClosingDebitCentavos() === $this->totalClosingCreditCentavos();
    }

    /**
     * How far out of balance the closing columns are, in centavos. Zero when
     * balanced; shown on the page so a discrepancy can be searched for by
     * amount.
     */
    public function closingVarianceCentavos(): int
    {
        return $this->totalClosingDebitCentavos() - $this->totalClosingCreditCentavos();
    }

    /** @return array<string, int|bool> */
    public function totalsToArray(): array
    {
        return [
            'opening_debit_centavos' => $this->totalOpeningDebitCentavos(),
            'opening_credit_centavos' => $this->totalOpeningCreditCentavos(),
            'period_debit_centavos' => $this->totalPeriodDebitCentavos(),
            'period_credit_centavos' => $this->totalPeriodCreditCentavos(),
            'closing_debit_centavos' => $this->totalClosingDebitCentavos(),
            'closing_credit_centavos' => $this->totalClosingCreditCentavos(),
            'is_balanced' => $this->isBalanced(),
            'closing_variance_centavos' => $this->closingVarianceCentavos(),
        ];
    }

    /** @return list<array<string, int|string>> */
    public function rowsToArray(): array
    {
        return array_map(fn (TrialBalanceRow $row): array => $row->toArray(), $this->rows);
    }

    /** @param callable(TrialBalanceRow): int $extract */
    private function sum(callable $extract): int
    {
        return array_sum(array_map($extract, $this->rows));
    }
}
