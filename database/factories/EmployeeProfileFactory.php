<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pas\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeProfile>
 */
class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // No FK constraint on lms_staff_id (LMS lives on a read-only
            // connection). A unique random int suffices for factory rows.
            'lms_staff_id' => fake()->unique()->numberBetween(100_000, 999_999),
            'employment_classification' => fake()->randomElement(EmployeeProfile::EMPLOYMENT_CLASSIFICATIONS),
            'pay_frequency' => fake()->randomElement(['monthly', 'semi_monthly']),
            // PHP 15,000.00 to PHP 150,000.00 in centavos.
            'basic_salary_centavos' => fake()->numberBetween(1_500_000, 15_000_000),
            'tax_status' => null,
            'tin' => null,
            'sss_number' => null,
            'philhealth_number' => null,
            'pagibig_number' => null,
            'bank_name' => null,
            'bank_account_number' => null,
            'bank_account_name' => null,
            'date_hired' => fake()->dateTimeBetween('-5 years', '-1 month')->format('Y-m-d'),
            'date_terminated' => null,
            'is_active' => true,
        ];
    }
}
