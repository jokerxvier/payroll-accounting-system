<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonImmutable;

/**
 * One posted line on an account's General Ledger, carrying the running
 * balance as at that line.
 *
 * `contraAccounts` names the other side of the entry — the single column
 * that turns a list of amounts into something readable, because the question
 * a ledger is read to answer is "what was this movement *for*". Compiled from
 * the sibling lines of the same entry rather than guessed from the
 * narration.
 */
final readonly class AccountLedgerLine
{
    /** @param list<string> $contraAccounts */
    public function __construct(
        public int $lineId,
        public int $entryId,
        public ?string $entryNumber,
        public CarbonImmutable $date,
        public ?string $reference,
        public ?string $narration,
        public ?string $description,
        public int $debitCentavos,
        public int $creditCentavos,
        public int $runningRawCentavos,
        public array $contraAccounts,
        public bool $isReversal,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'line_id' => $this->lineId,
            'entry_id' => $this->entryId,
            'entry_number' => $this->entryNumber,
            'date' => $this->date->toDateString(),
            'reference' => $this->reference,
            'narration' => $this->narration,
            'description' => $this->description,
            'debit_centavos' => $this->debitCentavos,
            'credit_centavos' => $this->creditCentavos,
            'running_raw_centavos' => $this->runningRawCentavos,
            'contra_accounts' => $this->contraAccounts,
            'is_reversal' => $this->isReversal,
        ];
    }
}
