<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Pas\EmployeeProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmployeeIndexRequest extends FormRequest
{
    /**
     * Columns the directory may sort by.
     *
     * Both columns live on the LMS `sm_staffs` table — the only columns we can
     * sort by without a cross-connection JOIN (banned by the
     * EloquentEmployeeRepository contract). Sorting by profile-owned columns
     * (basic_salary_centavos, employment_classification) would require
     * inverting the pagination strategy and would silently exclude staff
     * without a profile from the results, so they are deferred.
     *
     * @var list<string>
     */
    public const SORTABLE_COLUMNS = ['staff_no', 'full_name'];

    public function authorize(): bool
    {
        return Gate::allows('viewAny', EmployeeProfile::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'department_id' => ['nullable', 'integer'],
            'employment_classification' => ['nullable', 'string', Rule::in(EmployeeProfile::EMPLOYMENT_CLASSIFICATIONS)],
            'sort_by' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }

    /**
     * Filter array shaped for EmployeeRepositoryInterface::paginate().
     *
     * Translates the user-facing `status=active|inactive` to the repo's
     * `is_active=bool` filter so the repo never has to know about UI labels.
     * Collapses `sort_by` + `sort_dir` into a nested `sort` shape so the repo
     * receives a single, opaque sort directive.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $v = $this->validated();
        $filters = [];

        if (! empty($v['search'])) {
            $filters['search'] = $v['search'];
        }
        if (! empty($v['status'])) {
            $filters['is_active'] = $v['status'] === 'active';
        }
        if (! empty($v['department_id'])) {
            $filters['department_id'] = (int) $v['department_id'];
        }
        if (! empty($v['employment_classification'])) {
            $filters['employment_classification'] = $v['employment_classification'];
        }
        if (! empty($v['sort_by'])) {
            $filters['sort'] = [
                'column' => $v['sort_by'],
                'direction' => $v['sort_dir'] ?? 'asc',
            ];
        }

        return $filters;
    }
}
