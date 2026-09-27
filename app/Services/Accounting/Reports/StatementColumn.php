<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonImmutable;

/**
 * One amount column of a statement: a label and the dates it covers.
 *
 * Every comparison the Income Statement offers — the period before, the same
 * dates last year, a column per month, a range the reader chose — is the same
 * thing underneath: some more date ranges, each summed the same way. Making
 * the column the unit is what lets one query, one table and one export serve
 * all of them.
 */
final readonly class StatementColumn
{
    public const ROLE_PRIMARY = 'primary';

    public const ROLE_COMPARISON = 'comparison';

    public const ROLE_MONTH = 'month';

    public const ROLE_TOTAL = 'total';

    public function __construct(
        public string $key,
        public string $label,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $role,
    ) {}

    /** @return array{key: string, label: string, from: string, to: string, role: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'role' => $this->role,
        ];
    }
}
