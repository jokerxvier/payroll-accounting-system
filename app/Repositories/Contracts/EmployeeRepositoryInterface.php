<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Pas\EmployeeProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EmployeeRepositoryInterface
{
    /**
     * Paginated list of employees, joining LMS staff identity with payroll profile data.
     *
     * Returns a paginator of stdClass-like rows (each row exposes both LMS identity
     * fields — name, email, department, role — and the payroll profile fields when
     * a profile row exists). Filtered by config('payroll.employee_role_allowlist').
     *
     * Supported filters:
     *  - search: string (matches LMS staff full_name, email, staff_no)
     *  - is_active: bool (filters on pas_employee_profiles.is_active when a profile exists)
     *  - employment_classification: string
     *  - department_id: int (LMS sm_human_departments.id)
     *  - sort: array{column: string, direction: 'asc'|'desc'} — must be a column
     *    on the LMS `sm_staffs` table (e.g. `staff_no`, `full_name`). Profile-owned
     *    columns are NOT sortable here; the cross-connection JOIN ban (see the
     *    EloquentEmployeeRepository class docblock) makes them require an
     *    inverted pagination strategy that silently drops staff without a profile.
     *    Callers must validate the column allowlist (see EmployeeIndexRequest::SORTABLE_COLUMNS).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;

    public function findByStaffId(int $staffId): ?EmployeeProfile;

    /**
     * Resolve a single employee detail row composed from LMS identity and the
     * (optional) payroll profile.
     *
     * Returns `null` when:
     *  - the staff_id does not exist in `sm_staffs`, OR
     *  - the staff's `role_id` is not in `config('payroll.employee_role_allowlist')`.
     *
     * The returned object is a stdClass exposing:
     *  - lms_staff_id: int
     *  - staff_no: ?string
     *  - full_name: ?string
     *  - email: ?string
     *  - department: ?object{id:int,name:string}
     *  - designation: ?object{id:int,title:string}
     *  - role: ?object{id:int,name:string}
     *  - profile: ?\App\Models\Pas\EmployeeProfile  (the actual Eloquent model, nullable)
     *
     * NEVER writes to any LMS table — only reads from `sm_staffs` and its
     * adjacent reference tables on the `lms` connection.
     */
    public function findDetailByStaffId(int $staffId): ?object;

    /**
     * Return the existing profile for an LMS staff id, or create one if missing.
     *
     * Never writes to any LMS table — only inserts into pas_employee_profiles.
     *
     * @param  array<string, mixed>  $defaults
     */
    public function firstOrCreateForStaff(int $staffId, array $defaults = []): EmployeeProfile;
}
