<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * One control account's sub-ledger, measured against the account itself.
 *
 * `controlCentavos` is what the cutover snapshot put into AR or AP.
 * `itemsCentavos` is what the individual open items add up to. They should be
 * the same figure, and the difference is the finding.
 */
final readonly class OpeningItemReconciliation
{
    public function __construct(
        /** `receivable` or `payable`. */
        public string $key,
        public string $label,
        public int $controlCentavos,
        public int $itemsCentavos,
    ) {}

    /**
     * Control minus items.
     *
     * Positive means the control account holds more than the documents
     * explain — money is owed that nobody has a document to chase. Negative
     * means the reverse, and usually means an item was recorded twice.
     */
    public function differenceCentavos(): int
    {
        return $this->controlCentavos - $this->itemsCentavos;
    }

    public function isReconciled(): bool
    {
        return $this->differenceCentavos() === 0;
    }

    /**
     * Whether this side is worth showing at all.
     *
     * A school with no payables carries zero on both figures, and a
     * reconciliation panel that reports "₱0.00 against ₱0.00, balanced" is
     * noise dressed as reassurance.
     */
    public function isSignificant(): bool
    {
        return $this->controlCentavos !== 0 || $this->itemsCentavos !== 0;
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     control_centavos: int,
     *     items_centavos: int,
     *     difference_centavos: int,
     *     is_reconciled: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'control_centavos' => $this->controlCentavos,
            'items_centavos' => $this->itemsCentavos,
            'difference_centavos' => $this->differenceCentavos(),
            'is_reconciled' => $this->isReconciled(),
        ];
    }
}
