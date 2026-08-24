<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Pas\AccountingPeriod;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * `open → closed`. Stamps `closed_at` + the actor.
 *
 * Closing is the ledger's freeze switch: once a period is closed, Slice 2's
 * posting guard refuses to write any journal entry dated inside it, and
 * corrections must be made as reversing entries in an open period
 * (`rules/CODING_STANDARDS_LARAVEL.md` §434).
 *
 * The status guard here is defence in depth. `AccountingPeriodPolicy::close()`
 * already requires the period to be open, so a double-close through the UI is
 * impossible; this catches a programmatic caller that bypasses the policy.
 */
final class CloseAccountingPeriodAction
{
    public function execute(AccountingPeriod $period, int $actorUserId): AccountingPeriod
    {
        if (! $period->isOpen()) {
            throw new DomainException(sprintf(
                'Cannot close accounting period [%s] from status [%s]. Expected [open].',
                $period->code,
                $period->status,
            ));
        }

        return DB::transaction(function () use ($period, $actorUserId): AccountingPeriod {
            $period->forceFill([
                'status' => AccountingPeriod::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by_user_id' => $actorUserId,
            ])->save();

            return $period->fresh();
        });
    }
}
