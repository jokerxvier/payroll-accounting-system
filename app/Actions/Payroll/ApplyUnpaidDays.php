<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\EmployeeProfile;
use App\Services\Payroll\Breakdowns\UnpaidDaysReduction;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Apply a basic-pay reduction for unpaid leave days that fall inside a
 * single pay period.
 *
 * **Status: stubbed (returns zero) pending LMS schema clarification.**
 *
 * The LMS install on `payroll_db` exposes three leave-related tables:
 *
 *   - `sm_leave_requests` — the leave application header. Carries
 *     `staff_id` (FK to `users.id`, NOT `sm_staffs.id`), `leave_from`,
 *     `leave_to`, `approve_status` (varchar), `type_id`, plus standard
 *     LMS audit columns. There is NO `is_paid` flag and NO standardised
 *     status enum.
 *   - `sm_leave_types` — `type` (string), `total_days` (allowance budget),
 *     no taxability or paid/unpaid flag.
 *   - `sm_leave_defines` — per-user × per-type allowance ledger
 *     (`days`, `total_days` — granted vs taken).
 *   - `sm_leave_deduction_infos` — opaque per-pay-month roll-up keyed by
 *     `payroll_id` (an LMS-internal payroll FK that does not exist in
 *     this system) with `extra_leave` and `salary_deduct` integers.
 *
 * Without a clear "this leave is unpaid" signal in the LMS schema and
 * without a contract from the client confirming which leave_type_ids
 * count as unpaid, any heuristic this action could implement here is a
 * guess. Heuristics that misclassify a paid leave as unpaid silently
 * underpay an employee — the worst possible failure mode for a payroll
 * engine.
 *
 * Decision (Q-block): defer the LMS leave bridge to a follow-up commit
 * once the client confirms either:
 *   a) which `sm_leave_types.type` values map to "unpaid", OR
 *   b) which approve_status values count as approved-and-unpaid, OR
 *   c) we add a `pas_unpaid_leave_days` cache table populated by an
 *      explicit HR action (the safest option — explicit input, no
 *      cross-system guessing).
 *
 * Until that decision lands, this action returns
 * {@see UnpaidDaysReduction::zero()} for every input. The unit tests
 * pin that behavior so the engine layer (Chunk 4) can compose the
 * action without a placeholder. Once the LMS contract is signed off,
 * the implementation lands here in isolation — the engine signature
 * does not change.
 *
 * Future signature when implemented:
 *
 *   1. Resolve the LMS user_id from `$profile->lms_staff_id` via the
 *      `sm_staffs` bridge.
 *   2. Query `sm_leave_requests` for approved unpaid leave overlapping
 *      `[period.start(), period.end()]`.
 *   3. Sum the per-leave overlap day counts (clip leave_from / leave_to
 *      to the period boundaries; both inclusive).
 *   4. Reduction = `basicPay × unpaidDays / period.daysInPeriod()` via
 *      `Money::times()` + `Money::dividedBy()` (banker's rounding).
 *   5. Emit one `PayrollLineItem` (code `CODE_UNPAID_DAYS`, bucket
 *      `BUCKET_EMPLOYEE_DEDUCTION`) when reduction > 0; null line for
 *      zero-day cases so payslips for fully-worked periods stay clean.
 */
class ApplyUnpaidDays
{
    public function __invoke(
        EmployeeProfile $profile,
        PayPeriodInput $period,
        Money $basicPay,
    ): UnpaidDaysReduction {
        // TODO(week7-followup): wire to the LMS leave bridge once the
        //   client confirms the unpaid-leave contract (see class
        //   docblock). The engine path is already plumbed to subtract
        //   the reduction from basicPay; flipping this stub to a real
        //   implementation requires no engine change.
        unset($profile, $period, $basicPay);

        return UnpaidDaysReduction::zero();
    }
}
