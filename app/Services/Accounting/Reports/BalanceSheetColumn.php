<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonImmutable;

/**
 * One date on a Balance Sheet, and whether it balances.
 *
 * Each date carries its own proof. Two dates side by side are two statements,
 * and a ledger that went wrong in March balances as at February and not as at
 * April — a single verdict for the page would have to pick one.
 */
final readonly class BalanceSheetColumn
{
    /**
     * @param  CarbonImmutable  $fiscalYearStart  where "this year" begins for
     *                                            this date's earnings split
     */
    public function __construct(
        public CarbonImmutable $asAt,
        public CarbonImmutable $fiscalYearStart,
        public int $assetsCentavos,
        public int $liabilitiesCentavos,
        public int $equityCentavos,
    ) {}

    public function liabilitiesAndEquityCentavos(): int
    {
        return $this->liabilitiesCentavos + $this->equityCentavos;
    }

    /**
     * Assets less liabilities and equity. Zero on a ledger where every line
     * arrived through posting; anything else means one did not.
     */
    public function varianceCentavos(): int
    {
        return $this->assetsCentavos - $this->liabilitiesAndEquityCentavos();
    }

    public function isBalanced(): bool
    {
        return $this->varianceCentavos() === 0;
    }

    public function label(): string
    {
        return 'As at '.$this->asAt->format('j M Y');
    }

    /** @return array<string, int|bool|string> */
    public function toArray(): array
    {
        return [
            'label' => $this->label(),
            'as_at' => $this->asAt->toDateString(),
            'fiscal_year_start' => $this->fiscalYearStart->toDateString(),
            'assets_centavos' => $this->assetsCentavos,
            'liabilities_centavos' => $this->liabilitiesCentavos,
            'equity_centavos' => $this->equityCentavos,
            'liabilities_and_equity_centavos' => $this->liabilitiesAndEquityCentavos(),
            'is_balanced' => $this->isBalanced(),
            'variance_centavos' => $this->varianceCentavos(),
        ];
    }
}
