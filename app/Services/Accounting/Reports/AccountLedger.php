<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Pas\ChartOfAccount;
use Carbon\CarbonImmutable;

/**
 * One account's General Ledger across a date range: what it carried in,
 * every posted movement in date order, and what it carried out.
 *
 * Balances are held raw (`debits − credits`) for the same reason as
 * {@see TrialBalanceRow} — the running balance has to add up column by
 * column, and signing it per normal balance first makes the arithmetic on
 * the page stop working. The natural-direction reading is derived on demand.
 */
final readonly class AccountLedger
{
    /** @param list<AccountLedgerLine> $lines */
    public function __construct(
        public ChartOfAccount $account,
        public ?CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $openingRawCentavos,
        public array $lines,
    ) {}

    public function closingRawCentavos(): int
    {
        return $this->openingRawCentavos
            + $this->totalDebitCentavos()
            - $this->totalCreditCentavos();
    }

    public function totalDebitCentavos(): int
    {
        return array_sum(array_map(
            fn (AccountLedgerLine $line): int => $line->debitCentavos,
            $this->lines,
        ));
    }

    public function totalCreditCentavos(): int
    {
        return array_sum(array_map(
            fn (AccountLedgerLine $line): int => $line->creditCentavos,
            $this->lines,
        ));
    }

    /** The closing balance stated in the account's own direction. */
    public function closingNaturalCentavos(): int
    {
        return $this->account->movementCentavos(
            $this->openingRawCentavos + $this->totalDebitCentavos(),
            $this->totalCreditCentavos(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account' => [
                'id' => $this->account->getKey(),
                'code' => $this->account->code,
                'name' => $this->account->name,
                'type' => $this->account->type,
                'normal_balance' => $this->account->normal_balance,
            ],
            'opening_raw_centavos' => $this->openingRawCentavos,
            'closing_raw_centavos' => $this->closingRawCentavos(),
            'closing_natural_centavos' => $this->closingNaturalCentavos(),
            'total_debit_centavos' => $this->totalDebitCentavos(),
            'total_credit_centavos' => $this->totalCreditCentavos(),
            'lines' => array_map(
                fn (AccountLedgerLine $line): array => $line->toArray(),
                $this->lines,
            ),
        ];
    }
}
