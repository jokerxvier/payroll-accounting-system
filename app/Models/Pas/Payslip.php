<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PayslipFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's computed payslip within a payroll run. Immutable snapshot —
 * the engine's full {@see PayrollLineItem} array is frozen into `audit_lines`,
 * the per-employee exemption list at compute time is frozen into
 * `applied_exemptions`, and the totals are mirrored from the engine output.
 *
 * Snapshotting (rather than re-computing on render) keeps payroll history
 * stable even after rates, salaries, or exemption lists change.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $employee_profile_id
 * @property int $lms_staff_id
 * @property int $gross_pay_centavos
 * @property int $total_employee_deductions_centavos
 * @property int $total_employer_contributions_centavos
 * @property int $net_pay_centavos
 * @property int $taxable_income_centavos
 * @property array<int, array<string, mixed>> $audit_lines
 * @property list<string>|null $applied_exemptions
 * @property CarbonImmutable $computed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Payslip extends Model
{
    use Auditable;
    use HasFactory;

    protected $table = 'pas_payslips';

    protected $fillable = [
        'payroll_run_id',
        'employee_profile_id',
        'lms_staff_id',
        'gross_pay_centavos',
        'total_employee_deductions_centavos',
        'total_employer_contributions_centavos',
        'net_pay_centavos',
        'taxable_income_centavos',
        'audit_lines',
        'applied_exemptions',
        'computed_at',
    ];

    protected static function newFactory(): Factory
    {
        return PayslipFactory::new();
    }

    protected function casts(): array
    {
        return [
            'payroll_run_id' => 'integer',
            'employee_profile_id' => 'integer',
            'lms_staff_id' => 'integer',
            'gross_pay_centavos' => 'integer',
            'total_employee_deductions_centavos' => 'integer',
            'total_employer_contributions_centavos' => 'integer',
            'net_pay_centavos' => 'integer',
            'taxable_income_centavos' => 'integer',
            'audit_lines' => 'array',
            'applied_exemptions' => 'array',
            'computed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PayrollRun, Payslip> */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /** @return BelongsTo<EmployeeProfile, Payslip> */
    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function grossPay(): Money
    {
        return Money::fromCentavos($this->gross_pay_centavos);
    }

    public function totalEmployeeDeductions(): Money
    {
        return Money::fromCentavos($this->total_employee_deductions_centavos);
    }

    public function totalEmployerContributions(): Money
    {
        return Money::fromCentavos($this->total_employer_contributions_centavos);
    }

    public function netPay(): Money
    {
        return Money::fromCentavos($this->net_pay_centavos);
    }

    public function taxableIncome(): Money
    {
        return Money::fromCentavos($this->taxable_income_centavos);
    }

    /**
     * Hydrate the frozen audit lines back into PayrollLineItem instances.
     * Used by the renderer/payslip view layer (Week 11).
     *
     * @return list<PayrollLineItem>
     */
    public function hydratedAuditLines(): array
    {
        /** @var array<int, array{code: string, label: string, amount: int, bucket: string, meta?: array<string, mixed>|null}> $rows */
        $rows = $this->audit_lines;

        return array_values(array_map(
            fn (array $row): PayrollLineItem => new PayrollLineItem(
                code: $row['code'],
                label: $row['label'],
                amount: Money::fromCentavos((int) $row['amount']),
                bucket: $row['bucket'],
                meta: $row['meta'] ?? null,
            ),
            $rows,
        ));
    }
}
