<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payroll\SeedDemoSalariesAction;
use Illuminate\Console\Command;

/**
 * Dev/demo helper: assigns sensible random basic salaries to active
 * employees currently at zero. See {@see SeedDemoSalariesAction} for
 * the canonical logic shared with the admin button.
 */
final class SeedDemoSalaries extends Command
{
    /** @var string */
    protected $signature = 'payroll:seed-demo-salaries
        {--min=2500000 : Minimum basic salary in centavos (default ₱25,000.00)}
        {--max=7500000 : Maximum basic salary in centavos (default ₱75,000.00)}';

    /** @var string */
    protected $description = 'Assign random basic salaries to active employees whose salary is currently zero. Idempotent — never touches non-zero rows.';

    public function handle(SeedDemoSalariesAction $action): int
    {
        $min = (int) $this->option('min');
        $max = (int) $this->option('max');

        $touched = $action->execute($min, $max);

        $this->info(sprintf(
            'Seeded demo salaries on %d profiles (range: %s – %s).',
            $touched,
            number_format($min / 100, 2),
            number_format($max / 100, 2),
        ));

        return self::SUCCESS;
    }
}
