<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Pas\PayrollRun;
use Illuminate\Support\Collection;

/**
 * Read-side repository for `pas_payroll_runs` consumed by the dashboard.
 *
 * Both methods scope out voided runs (`voided_at IS NULL`) — the dashboard's
 * "latest" and "recent" lists are decision-support surfaces and a voided run
 * is not the run an operator wants to act on next.
 */
interface PayrollRunRepositoryInterface
{
    /**
     * The most recent non-voided run, with its pay period eager-loaded for
     * label rendering. Returns null when no run has been generated yet.
     */
    public function latestNonVoided(): ?PayrollRun;

    /**
     * The N most recent non-voided runs, eager-loaded with pay period.
     *
     * @return Collection<int, PayrollRun>
     */
    public function recentNonVoided(int $limit): Collection;
}
