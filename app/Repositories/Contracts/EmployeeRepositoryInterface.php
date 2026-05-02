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
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;

    public function findByStaffId(int $staffId): ?EmployeeProfile;

    /**
     * Return the existing profile for an LMS staff id, or create one if missing.
     *
     * Never writes to any LMS table — only inserts into pas_employee_profiles.
     *
     * @param  array<string, mixed>  $defaults
     */
    public function firstOrCreateForStaff(int $staffId, array $defaults = []): EmployeeProfile;
}
