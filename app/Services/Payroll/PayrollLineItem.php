<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Single audit line in a {@see PayrollComputationResult}.
 *
 * The engine emits one `PayrollLineItem` per discrete contribution to gross
 * pay, employee deduction, or employer cost. The collection of line items is
 * the authoritative payslip narrative — totals are derived from these lines,
 * never the other way around.
 *
 * Buckets are constrained to three values:
 *
 *   - `earning`              — adds to gross pay (basic pay, allowances, OT, …).
 *   - `employee_deduction`   — subtracted from gross to arrive at net pay
 *                              (SSS/PhilHealth/Pag-IBIG employee shares,
 *                              BIR withholding tax, …).
 *   - `employer_contribution` — paid by the employer alongside payroll;
 *                              never affects employee net pay (SSS employer
 *                              share, EC, PhilHealth/Pag-IBIG employer share, …).
 *
 * `meta` carries strategy-specific diagnostics (the SSS bracket id, the BIR
 * bracket label, the PhilHealth ceiling that was applied, …). It is opaque to
 * the engine — only the renderer / payslip view consumes it.
 */
final readonly class PayrollLineItem
{
    public const BUCKET_EARNING = 'earning';

    public const BUCKET_EMPLOYEE_DEDUCTION = 'employee_deduction';

    public const BUCKET_EMPLOYER_CONTRIBUTION = 'employer_contribution';

    /** @var list<string> */
    private const ALLOWED_BUCKETS = [
        self::BUCKET_EARNING,
        self::BUCKET_EMPLOYEE_DEDUCTION,
        self::BUCKET_EMPLOYER_CONTRIBUTION,
    ];

    public const CODE_BASIC_PAY = 'BASIC_PAY';

    public const CODE_BIR_WITHHOLDING = 'BIR_WITHHOLDING';

    public const CODE_SSS_EMPLOYEE = 'SSS_EMPLOYEE';

    public const CODE_SSS_EMPLOYER = 'SSS_EMPLOYER';

    public const CODE_SSS_EMPLOYER_EC = 'SSS_EMPLOYER_EC';

    public const CODE_PHILHEALTH_EMPLOYEE = 'PHILHEALTH_EMPLOYEE';

    public const CODE_PHILHEALTH_EMPLOYER = 'PHILHEALTH_EMPLOYER';

    public const CODE_PAGIBIG_EMPLOYEE = 'PAGIBIG_EMPLOYEE';

    public const CODE_PAGIBIG_EMPLOYER = 'PAGIBIG_EMPLOYER';

    public const CODE_ALLOWANCE_TAXABLE = 'allowance_taxable';

    public const CODE_ALLOWANCE_NON_TAXABLE = 'allowance_non_taxable';

    public const CODE_LOAN_AMORTIZATION = 'loan_amortization';

    public const CODE_CUSTOM_DEDUCTION = 'custom_deduction';

    public const CODE_ADJUSTMENT_ADDITION = 'adjustment_addition';

    public const CODE_ADJUSTMENT_DEDUCTION = 'adjustment_deduction';

    public const CODE_UNPAID_DAYS = 'unpaid_days';

    /**
     * @param  ?array<string, mixed>  $meta
     *
     * @throws InvalidArgumentException When `$bucket` is not a recognised value.
     */
    public function __construct(
        public string $code,
        public string $label,
        public Money $amount,
        public string $bucket,
        public ?array $meta = null,
    ) {
        if (! in_array($bucket, self::ALLOWED_BUCKETS, true)) {
            throw new InvalidArgumentException(
                "PayrollLineItem received unknown bucket '{$bucket}'; expected one of: "
                .implode(', ', self::ALLOWED_BUCKETS).'.'
            );
        }
    }

    /**
     * Wire-shape serialisation for Inertia / JSON consumers.
     *
     * Money is exposed as both `centavos` (the authoritative integer) and
     * `formatted` (a 2-decimal string the frontend can render verbatim).
     * The frontend never multiplies or divides amounts; it presents the
     * formatted string as-is. Banker's-rounded computation has already
     * happened upstream at the Money layer.
     *
     * @return array{
     *     code: string,
     *     label: string,
     *     amount: array{centavos: int, formatted: string},
     *     bucket: string,
     *     meta: ?array<string, mixed>,
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'amount' => [
                'centavos' => $this->amount->centavos(),
                'formatted' => $this->amount->toDecimalString(),
            ],
            'bucket' => $this->bucket,
            'meta' => $this->meta,
        ];
    }
}
