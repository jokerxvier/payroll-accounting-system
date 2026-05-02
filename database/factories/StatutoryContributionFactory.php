<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pas\StatutoryContribution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatutoryContribution>
 */
class StatutoryContributionFactory extends Factory
{
    protected $model = StatutoryContribution::class;

    /**
     * Default: a tiny generic bracket_table sample so ::factory()->create()
     * works without arguments. Use the named state methods below for
     * algorithm-specific shapes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contribution_code' => 'GENERIC',
            'label' => 'Generic contribution',
            'algorithm' => StatutoryContribution::ALGORITHM_BRACKET_TABLE,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rules' => [
                'period_types' => [
                    'monthly' => [
                        ['lower' => 0, 'upper' => 1_000_000, 'base_tax' => 0, 'excess_rate_bp' => 0, 'excess_over' => 0],
                    ],
                ],
            ],
            'notes' => null,
        ];
    }

    /**
     * Realistic-shape but minimal-data BIR withholding sample (TRAIN-style).
     * Three brackets per period_type, enough to exercise boundary tests.
     */
    public function bir(): self
    {
        return $this->state(fn () => [
            'contribution_code' => StatutoryContribution::CODE_BIR,
            'label' => 'BIR Withholding Tax',
            'algorithm' => StatutoryContribution::ALGORITHM_BRACKET_TABLE,
            'rules' => [
                'period_types' => [
                    'monthly' => [
                        ['lower' => 0, 'upper' => 2_083_300, 'base_tax' => 0, 'excess_rate_bp' => 0, 'excess_over' => 0],
                        ['lower' => 2_083_300, 'upper' => 3_333_300, 'base_tax' => 0, 'excess_rate_bp' => 1500, 'excess_over' => 2_083_300],
                        ['lower' => 3_333_300, 'upper' => null, 'base_tax' => 187_500, 'excess_rate_bp' => 2000, 'excess_over' => 3_333_300],
                    ],
                    'semi_monthly' => [
                        ['lower' => 0, 'upper' => 1_041_700, 'base_tax' => 0, 'excess_rate_bp' => 0, 'excess_over' => 0],
                        ['lower' => 1_041_700, 'upper' => 1_666_700, 'base_tax' => 0, 'excess_rate_bp' => 1500, 'excess_over' => 1_041_700],
                        ['lower' => 1_666_700, 'upper' => null, 'base_tax' => 93_750, 'excess_rate_bp' => 2000, 'excess_over' => 1_666_700],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Realistic-shape SSS sample. Three bands; the top band has upper=null.
     */
    public function sss(): self
    {
        return $this->state(fn () => [
            'contribution_code' => StatutoryContribution::CODE_SSS,
            'label' => 'SSS contribution',
            'algorithm' => StatutoryContribution::ALGORITHM_SALARY_BAND,
            'rules' => [
                'bands' => [
                    [
                        'lower' => 0,
                        'upper' => 525_000,
                        'contribution_base' => 500_000,
                        'employee_share' => 22_500,
                        'employer_share' => 47_500,
                        'employer_aux_share' => 1_000,
                        'employee_aux_share' => 0,
                    ],
                    [
                        'lower' => 525_000,
                        'upper' => 1_975_000,
                        'contribution_base' => 1_500_000,
                        'employee_share' => 67_500,
                        'employer_share' => 142_500,
                        'employer_aux_share' => 1_000,
                        'employee_aux_share' => 0,
                    ],
                    [
                        'lower' => 1_975_000,
                        'upper' => null,
                        'contribution_base' => 2_000_000,
                        'employee_share' => 90_000,
                        'employer_share' => 190_000,
                        'employer_aux_share' => 3_000,
                        'employee_aux_share' => 0,
                    ],
                ],
            ],
        ]);
    }

    /**
     * PhilHealth-style: single rate with floor and ceiling, 50/50 split.
     */
    public function philhealth(): self
    {
        return $this->state(fn () => [
            'contribution_code' => StatutoryContribution::CODE_PHILHEALTH,
            'label' => 'PhilHealth premium',
            'algorithm' => StatutoryContribution::ALGORITHM_PERCENTAGE_WITH_CAP,
            'rules' => [
                'rate_bp' => 500,
                'floor' => 1_000_000,
                'ceiling' => 10_000_000,
                'split' => [
                    'employee_bp' => 5_000,
                    'employer_bp' => 5_000,
                ],
            ],
        ]);
    }

    /**
     * Pag-IBIG-style: tiered percentage with cap.
     */
    public function pagibig(): self
    {
        return $this->state(fn () => [
            'contribution_code' => StatutoryContribution::CODE_PAGIBIG,
            'label' => 'Pag-IBIG contribution',
            'algorithm' => StatutoryContribution::ALGORITHM_TIERED_PERCENTAGE,
            'rules' => [
                'threshold' => 150_000,
                'lower' => ['ee_bp' => 100, 'er_bp' => 200],
                'upper' => ['ee_bp' => 200, 'er_bp' => 200],
                'max_msc' => 500_000,
            ],
        ]);
    }
}
