<?php

declare(strict_types=1);

namespace App\Services\Statutory;

use App\Models\Pas\StatutoryContribution;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only catalog of statutory contributions active on a given date.
 *
 * The payroll engine asks the registry "which contributions apply to this
 * pay date, and in what sequence?" — the answer is a date-filtered, ordered
 * Collection of {@see StatutoryContribution} rows. Per-row computation is the
 * job of {@see StatutoryContributionResolver}; the registry only exposes the
 * deterministic application sequence (SSS → PhilHealth → Pag-IBIG → BIR for
 * the v1 PH set, ordered by `application_order` with `contribution_code` as
 * the tiebreaker).
 *
 * Effective-date semantics mirror the model's `scopeForDate`: a row is
 * active for `effective_from <= date` AND (`effective_to` IS NULL OR
 * `effective_to` > date) — `effective_to` is exclusive so adjacent versions
 * never overlap.
 */
final readonly class StatutoryContributionRegistry
{
    /**
     * Return every contribution whose effective range covers `$date`,
     * ordered by `application_order` ASC then `contribution_code` ASC.
     *
     * `notVoided()` is chained explicitly (in addition to the same chain
     * inside `scopeForDate`) as defense in depth: voided rows are never a
     * valid input to the payroll engine, and a future refactor of
     * `scopeForDate` should never quietly break that invariant.
     *
     * @return Collection<int, StatutoryContribution>
     */
    public function active(CarbonInterface $date): Collection
    {
        return StatutoryContribution::query()
            ->forDate($date)
            ->notVoided()
            ->orderedForApplication()
            ->get();
    }
}
