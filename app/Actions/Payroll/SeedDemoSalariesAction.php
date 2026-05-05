<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\EmployeeProfile;

/**
 * Dev/demo affordance: assigns a sensible random basic salary to every
 * active employee whose `basic_salary_centavos` is currently 0.
 *
 * Idempotent by design — only touches zero-salary rows, so running it
 * twice never churns real configured salaries. Both the artisan command
 * and the admin button on /admin/employees call this single source of truth.
 */
final class SeedDemoSalariesAction
{
    /**
     * @param  int  $minCentavos  default ₱25,000.00
     * @param  int  $maxCentavos  default ₱75,000.00
     * @return int number of profiles touched
     */
    public function execute(int $minCentavos = 2_500_000, int $maxCentavos = 7_500_000): int
    {
        if ($minCentavos < 0 || $maxCentavos < $minCentavos) {
            return 0;
        }

        $profiles = EmployeeProfile::query()
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('basic_salary_centavos')
                    ->orWhere('basic_salary_centavos', 0);
            })
            ->get();

        // Round-thousand pesos = round-100,000 centavos. Quantise to keep
        // the demo numbers tidy (e.g. ₱42,000.00 not ₱42,317.55).
        $minThousands = (int) ceil($minCentavos / 100_000);
        $maxThousands = (int) floor($maxCentavos / 100_000);

        if ($maxThousands < $minThousands) {
            return 0;
        }

        $touched = 0;
        foreach ($profiles as $profile) {
            $profile->forceFill([
                'basic_salary_centavos' => random_int($minThousands, $maxThousands) * 100_000,
            ])->save();
            $touched++;
        }

        return $touched;
    }
}
