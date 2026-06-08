<?php

declare(strict_types=1);

namespace App\Actions\Employee;

use App\Models\Pas\EmployeeProfile;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates payroll profiles for every LMS staff in the current tenant's
 * role allowlist that doesn't yet have one. Idempotent — re-running
 * after every staff has a profile is a no-op (firstOrCreateForStaff
 * keys on the lms_staff_id unique index).
 *
 * Defaults: ₱0 salary, Regular, Monthly, Active. Operators edit each
 * via the row-level Quick edit afterwards.
 *
 * Wraps in a DB transaction so a mid-loop failure rolls back. Tenant
 * scope is enforced by the BelongsToTenant trait on EmployeeProfile,
 * which auto-fills school_id from Tenant::current() on the creating
 * event.
 *
 * Counts created rows by diffing the EmployeeProfile table size across
 * the transaction. firstOrCreate doesn't expose a "wasCreated" flag for
 * the existing path, so the diff is the simplest authoritative count.
 */
final class BulkSetupMissingProfiles
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $repo,
    ) {}

    /**
     * @return int Number of profiles newly created.
     */
    public function __invoke(): int
    {
        // Pull every staff in the current tenant via the repo. Use a
        // very large per-page so we don't paginate (admin one-shot).
        $page = $this->repo->paginate([], perPage: 10_000);

        $defaults = [
            'basic_salary_centavos' => 0,
            'employment_classification' => 'regular',
            'pay_frequency' => 'monthly',
            'is_active' => true,
        ];

        $created = 0;

        DB::transaction(function () use ($page, $defaults, &$created): void {
            foreach ($page->items() as $row) {
                if ($row->has_profile) {
                    continue;
                }

                $existedBefore = EmployeeProfile::query()
                    ->withoutGlobalScopes()
                    ->where('lms_staff_id', $row->lms_staff_id)
                    ->exists();

                $this->repo->firstOrCreateForStaff(
                    (int) $row->lms_staff_id,
                    $defaults,
                );

                if (! $existedBefore) {
                    $created++;
                }
            }
        });

        return $created;
    }
}
