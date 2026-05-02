<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Pas\EmployeeProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmployeeIndexRequest extends FormRequest
{
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
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }

    /**
     * Filter array shaped for EmployeeRepositoryInterface::paginate().
     *
     * Translates the user-facing `status=active|inactive` to the repo's
     * `is_active=bool` filter so the repo never has to know about UI labels.
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

        return $filters;
    }
}
