<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accounting\GenerateDueInvoices;
use App\Models\Pas\School;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Raises the drafts every due schedule owes, for every active school.
 *
 * The app's first scheduled business task. Until now nothing depended on
 * `schedule:run` actually being wired up on the server; from tonight, a school
 * stops being billed if it is not.
 *
 * A thin shell over {@see GenerateDueInvoices} — the same split as
 * `SeedDemoSalaries`, so the work can be exercised from a test without a
 * console.
 */
final class GenerateRecurringInvoices extends Command
{
    /** @var string */
    protected $signature = 'invoices:generate-recurring
        {--school= : Slug or id of a single school, for a targeted re-run}
        {--date= : Generate as at this date (YYYY-MM-DD) instead of today}
        {--dry-run : Report what would be raised without writing anything}';

    /** @var string */
    protected $description = 'Raise draft invoices for every recurring schedule that is due';

    public function handle(GenerateDueInvoices $action): int
    {
        // Manila, not UTC. config/app.php is UTC while the schools are UTC+8,
        // so a bare now() is the *previous* day between midnight and 08:00
        // local — a schedule dated the 1st would generate on what Manila still
        // calls the 31st. Same reasoning as EloquentEmployeeRepository.
        $date = $this->option('date') !== null
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Manila')
            : CarbonImmutable::now('Asia/Manila');

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Dry run — nothing will be written.');
        }

        $totals = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'schedules' => 0];

        foreach ($this->schools() as $school) {
            try {
                // Spatie's own wrapper, not a hand-rolled makeCurrent/finally.
                // It restores the *previous* tenant rather than blanking it,
                // and it covers makeCurrent itself — which matters because
                // makeCurrent forgets the current tenant before running the
                // switch tasks, so a throw inside one would otherwise escape
                // with no tenant set, straight into BelongsToTenant's
                // fail-open behaviour.
                $counts = $school->execute(
                    fn (): array => $action->execute($date, $dryRun),
                );
            } catch (Throwable $e) {
                // One school's broken connection or chart must not stop the
                // rest of the country being billed.
                report($e);
                $this->error(sprintf('%s: %s', $school->name, $e->getMessage()));

                continue;
            }

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }

            if ($counts['schedules'] > 0) {
                $this->line(sprintf(
                    '%s — %d raised, %d skipped, %d failed, from %d due.',
                    $school->name,
                    $counts['generated'],
                    $counts['skipped'],
                    $counts['failed'],
                    $counts['schedules'],
                ));
            }
        }

        $this->info(sprintf(
            'As at %s: %d raised, %d skipped, %d failed, from %d due schedules.',
            $date->toDateString(),
            $totals['generated'],
            $totals['skipped'],
            $totals['failed'],
            $totals['schedules'],
        ));

        // A failed schedule is reported, not fatal: exiting non-zero would
        // make the scheduler treat a single bad payer as a broken run.
        return self::SUCCESS;
    }

    /**
     * @return Collection<int, School>
     */
    private function schools()
    {
        $query = School::query()->where('is_active', true)->orderBy('id');

        $only = $this->option('school');

        if ($only !== null) {
            $query->where(function ($q) use ($only): void {
                $q->where('slug', $only)->orWhere('id', $only);
            });
        }

        // get(), not cursor(): the table is tiny, and an unbuffered result set
        // held open while the loop issues its own queries on the same
        // connection is a fragility with nothing to buy it.
        return $query->get();
    }
}
