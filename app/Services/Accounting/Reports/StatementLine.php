<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Support\Centavos;

/**
 * One printed line of a statement, in the order it is printed.
 *
 * A statement is sent as a flat list of these rather than as nested sections.
 * The page, the spreadsheet and the PDF all have to print the same lines in
 * the same order with the same subtotals, and a flat list leaves none of them
 * anything to decide.
 *
 * Amounts are in each account's natural direction, so an ordinary balance is
 * positive and a contra account — Accumulated Depreciation, Drawings — is
 * negative. Subtotals are plain sums of those.
 */
final readonly class StatementLine
{
    /** A section title. Carries no amounts. */
    public const KIND_HEADING = 'heading';

    public const KIND_ACCOUNT = 'account';

    /** A figure derived at read time that no single account holds. */
    public const KIND_COMPUTED = 'computed';

    /** What an account or computed line is made of. Not added to subtotals. */
    public const KIND_DETAIL = 'detail';

    public const KIND_SUBTOTAL = 'subtotal';

    public const KIND_TOTAL = 'total';

    public const DRILL_GENERAL_LEDGER = 'general-ledger';

    public const DRILL_INCOME_STATEMENT = 'income-statement';

    /**
     * @param  list<int>  $amountsCentavos  one per column, in column order
     * @param  array{report: string, from: string, to: string}|null  $drill
     *                                                                       where this line's figure can be read in full. Null on
     *                                                                       an Income Statement account line, whose every column
     *                                                                       drills to its own dates.
     */
    public function __construct(
        public string $kind,
        public string $key,
        public string $section,
        public string $name,
        public array $amountsCentavos = [],
        public ?int $accountId = null,
        public ?string $code = null,
        public bool $isActive = true,
        public bool $isUnclassified = false,
        public ?int $varianceCentavos = null,
        public ?int $varianceBasisPoints = null,
        public ?bool $varianceFavourable = null,
        public ?array $drill = null,
    ) {}

    public static function heading(string $section, string $name): self
    {
        return new self(self::KIND_HEADING, "heading:{$section}", $section, $name);
    }

    /**
     * The same line with its first two columns compared.
     *
     * @param  bool|null  $moreIsBetter  true for income and profit, false
     *                                   for an expense. Decides whether an
     *                                   increase is good news; the sign of
     *                                   the variance itself is always current
     *                                   minus comparison. Null where neither
     *                                   direction is better — on a Balance
     *                                   Sheet more cash and a bigger loan are
     *                                   both just larger.
     */
    public function compared(?bool $moreIsBetter): self
    {
        if (count($this->amountsCentavos) < 2) {
            return $this;
        }

        [$current, $comparison] = $this->amountsCentavos;
        $variance = $current - $comparison;

        return new self(
            $this->kind,
            $this->key,
            $this->section,
            $this->name,
            $this->amountsCentavos,
            $this->accountId,
            $this->code,
            $this->isActive,
            $this->isUnclassified,
            $variance,
            Centavos::changeBasisPoints($current, $comparison),
            $variance === 0 || $moreIsBetter === null ? null : ($variance > 0) === $moreIsBetter,
            $this->drill,
        );
    }

    /**
     * The same line carrying different figures.
     *
     * @param  list<int>  $amountsCentavos
     */
    public function withAmounts(array $amountsCentavos): self
    {
        return new self(
            $this->kind,
            $this->key,
            $this->section,
            $this->name,
            $amountsCentavos,
            $this->accountId,
            $this->code,
            $this->isActive,
            $this->isUnclassified,
            drill: $this->drill,
        );
    }

    public function isZero(): bool
    {
        foreach ($this->amountsCentavos as $amount) {
            if ($amount !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'key' => $this->key,
            'section' => $this->section,
            'name' => $this->name,
            'amounts_centavos' => $this->amountsCentavos,
            'account_id' => $this->accountId,
            'code' => $this->code,
            'is_active' => $this->isActive,
            'is_unclassified' => $this->isUnclassified,
            'variance_centavos' => $this->varianceCentavos,
            'variance_bp' => $this->varianceBasisPoints,
            'variance_favourable' => $this->varianceFavourable,
            'drill' => $this->drill,
        ];
    }
}
