<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Pas\AccountingPeriod;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * `closed → open`. Stamps `reopened_at` + the actor.
 *
 * Reopening is the most audit-sensitive action in the accounting module: it
 * un-freezes a period whose figures may already have been reported, so
 * entries can be added or reversed after the fact. It exists because a period
 * genuinely closed in error is otherwise unrecoverable — the alternative,
 * posting correcting entries into a later period, misstates both periods.
 *
 * Three things make the action traceable:
 *   - the narrower `AccountingRoles::CLOSE_PERIOD` authorization,
 *   - the dedicated `reopened_at` / `reopened_by_user_id` stamps on the row,
 *   - the ordinary `Auditable` before/after entry in `pas_audit_logs`.
 *
 * The prior `closed_at` / `closed_by_user_id` stamps are deliberately left
 * intact rather than nulled: they record that the period *was* closed and by
 * whom, which is exactly the history an auditor needs. A later close
 * overwrites them.
 */
final class ReopenAccountingPeriodAction
{
    public function execute(AccountingPeriod $period, int $actorUserId): AccountingPeriod
    {
        if (! $period->isClosed()) {
            throw new DomainException(sprintf(
                'Cannot reopen accounting period [%s] from status [%s]. Expected [closed].',
                $period->code,
                $period->status,
            ));
        }

        return DB::transaction(function () use ($period, $actorUserId): AccountingPeriod {
            $period->forceFill([
                'status' => AccountingPeriod::STATUS_OPEN,
                'reopened_at' => now(),
                'reopened_by_user_id' => $actorUserId,
            ])->save();

            return $period->fresh();
        });
    }
}
