<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorImpl;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Eloquent implementation of the employee repository.
 *
 * IMPORTANT — cross-connection query strategy:
 *
 * `App\Models\Lms\Staff` lives on the read-only `lms` connection while
 * `App\Models\Pas\EmployeeProfile` lives on the default (writable) `mysql`
 * connection. Even though both connections currently point at the same
 * physical database, treating them as separate is non-negotiable: a future
 * physical split must be a connection-string change with zero app code change
 * (PLAN.md §3).
 *
 * That rules out a single SQL `JOIN sm_staffs ON ...` from a `pas_*` query —
 * Eloquent issues that JOIN on the parent's connection, which would either
 * fail (when the LMS DB is on a different host) or, worse, accidentally write
 * `pas_*` data through the read-only connection.
 *
 * Strategy used by `paginate()`:
 *
 *   1. Page LMS staff (filtered by role allowlist + soft text search) on the
 *      `lms` connection, paginated server-side. This is one query.
 *   2. Collect the staff IDs on the current page and fetch their matching
 *      `pas_employee_profiles` rows in one `whereIn` query on the `mysql`
 *      connection. Indexed on `lms_staff_id` (unique).
 *   3. Compose stdClass rows pairing LMS identity + profile fields and wrap
 *      in a LengthAwarePaginator that reuses the LMS paginator's totals.
 *
 * Total: two queries per page, regardless of page size. No N+1, no cross-
 * connection JOIN, no implicit writes through the read-only connection.
 *
 * Do not "optimize" this with a JOIN.
 */
final class EloquentEmployeeRepository implements EmployeeRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        /** @var array<int, int> $allowlist */
        $allowlist = (array) config('payroll.employee_role_allowlist', []);

        $page = Paginator::resolveCurrentPage();

        $staffQuery = Staff::query()
            ->when($allowlist !== [], fn ($q) => $q->whereIn('role_id', $allowlist))
            ->when(
                isset($filters['search']) && $filters['search'] !== '',
                function ($q) use ($filters): void {
                    $term = '%'.$filters['search'].'%';
                    $q->where(function ($inner) use ($term): void {
                        $inner->where('full_name', 'like', $term)
                            ->orWhere('email', 'like', $term)
                            ->orWhere('staff_no', 'like', $term);
                    });
                }
            )
            ->when(
                isset($filters['department_id']),
                fn ($q) => $q->where('department_id', (int) $filters['department_id'])
            )
            ->orderBy('full_name');

        /** @var LengthAwarePaginator $staffPage */
        $staffPage = $staffQuery->paginate($perPage, ['*'], 'page', $page);

        /** @var Collection<int, Staff> $staffItems */
        $staffItems = $staffPage->getCollection();

        $staffIds = $staffItems->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $profilesQuery = EmployeeProfile::query()
            ->whereIn('lms_staff_id', $staffIds)
            ->when(
                array_key_exists('is_active', $filters),
                fn ($q) => $q->where('is_active', (bool) $filters['is_active'])
            )
            ->when(
                isset($filters['employment_classification']) && $filters['employment_classification'] !== '',
                fn ($q) => $q->where('employment_classification', (string) $filters['employment_classification'])
            );

        /** @var Collection<int, EmployeeProfile> $profilesByStaffId */
        $profilesByStaffId = $profilesQuery->get()->keyBy('lms_staff_id');

        // Honor profile-level filters by intersecting with the LMS page when filters were applied.
        $profileFiltersActive = array_key_exists('is_active', $filters)
            || (isset($filters['employment_classification']) && $filters['employment_classification'] !== '');

        $rows = $staffItems
            ->map(function (Staff $staff) use ($profilesByStaffId): object {
                /** @var EmployeeProfile|null $profile */
                $profile = $profilesByStaffId->get($staff->id);

                return (object) [
                    'lms_staff_id' => (int) $staff->id,
                    'staff_no' => $staff->staff_no,
                    'full_name' => $staff->full_name,
                    'email' => $staff->email,
                    'role_id' => $staff->role_id,
                    'department_id' => $staff->department_id,
                    'designation_id' => $staff->designation_id,
                    'profile' => $profile,
                    'has_profile' => $profile !== null,
                    'basic_salary_centavos' => $profile?->basic_salary_centavos ?? 0,
                    'employment_classification' => $profile?->employment_classification,
                    'pay_frequency' => $profile?->pay_frequency,
                    'is_active' => $profile?->is_active,
                ];
            })
            ->when(
                $profileFiltersActive,
                fn (Collection $c) => $c->filter(fn (object $row): bool => $row->has_profile)->values()
            )
            ->values();

        return new LengthAwarePaginatorImpl(
            items: $rows,
            total: $staffPage->total(),
            perPage: $staffPage->perPage(),
            currentPage: $staffPage->currentPage(),
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }

    public function findByStaffId(int $staffId): ?EmployeeProfile
    {
        return EmployeeProfile::query()
            ->where('lms_staff_id', $staffId)
            ->first();
    }

    public function firstOrCreateForStaff(int $staffId, array $defaults = []): EmployeeProfile
    {
        // Critical: this method only ever writes to pas_employee_profiles (default `mysql` connection).
        // It NEVER touches sm_staffs or any other LMS table — the LMS staff record is assumed to
        // already exist and is referenced by id only.
        return EmployeeProfile::query()->firstOrCreate(
            ['lms_staff_id' => $staffId],
            $defaults,
        );
    }
}
