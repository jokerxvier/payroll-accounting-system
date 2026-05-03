<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\DeductionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeductionType>
 */
class DeductionTypeFactory extends Factory
{
    protected $model = DeductionType::class;

    /**
     * Default: a generic fixed-amount, employee-borne, taxable deduction.
     * Use the named state methods (`hmo()`, `sssLoan()`, etc.) for
     * realistic Philippine catalog samples.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('DED_???'),
            'name' => fake()->words(2, true),
            'is_taxable' => true,
            'calc_method' => 'fixed',
            'source' => 'employee',
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function percent(): self
    {
        return $this->state(fn () => ['calc_method' => 'percent']);
    }

    public function employerOnly(): self
    {
        return $this->state(fn () => ['source' => 'employer']);
    }

    public function hmo(): self
    {
        return $this->state(fn () => [
            'code' => 'hmo',
            'name' => 'HMO Premium',
            'is_taxable' => false,
            'calc_method' => 'fixed',
            'source' => 'employee',
        ]);
    }

    public function sssLoan(): self
    {
        return $this->state(fn () => [
            'code' => 'sss_loan',
            'name' => 'SSS Salary Loan',
            'is_taxable' => false,
            'calc_method' => 'fixed',
            'source' => 'employee',
        ]);
    }

    public function uniform(): self
    {
        return $this->state(fn () => [
            'code' => 'uniform',
            'name' => 'Uniform Deduction',
            'is_taxable' => true,
            'calc_method' => 'fixed',
            'source' => 'employee',
        ]);
    }
}
