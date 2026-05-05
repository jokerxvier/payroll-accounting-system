<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Services\Payroll\PayrollLineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payslip>
 */
class PayslipFactory extends Factory
{
    protected $model = Payslip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $auditLines = [
            [
                'code' => PayrollLineItem::CODE_BASIC_PAY,
                'label' => 'Basic pay',
                'amount' => 4_500_000,
                'bucket' => PayrollLineItem::BUCKET_EARNING,
                'meta' => null,
            ],
            [
                'code' => 'SSS_EMPLOYEE',
                'label' => 'SSS contribution (employee)',
                'amount' => 90_000,
                'bucket' => PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                'meta' => ['contribution_base_centavos' => 4_500_000],
            ],
        ];

        return [
            'payroll_run_id' => PayrollRun::factory(),
            'employee_profile_id' => EmployeeProfile::factory(),
            'lms_staff_id' => fake()->unique()->numberBetween(100_000, 999_999),
            'gross_pay_centavos' => 4_500_000,
            'total_employee_deductions_centavos' => 90_000,
            'total_employer_contributions_centavos' => 0,
            'net_pay_centavos' => 4_410_000,
            'taxable_income_centavos' => 4_410_000,
            'audit_lines' => $auditLines,
            'applied_exemptions' => [],
            'computed_at' => now(),
        ];
    }
}
