<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeIndexRequest;
use App\Models\Lms\Department;
use App\Models\Pas\EmployeeProfile;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $repo,
    ) {}

    public function index(EmployeeIndexRequest $request): Response
    {
        $filters = $request->filters();
        $perPage = (int) ($request->validated('per_page') ?? 25);

        $employees = $this->repo->paginate($filters, $perPage);

        return Inertia::render('employees/index', [
            'employees' => $employees,
            'filters' => $request->only(['search', 'status', 'department_id', 'employment_classification', 'per_page']),
            'departmentOptions' => Department::query()->orderBy('name')->get(['id', 'name']),
            'employmentTypeOptions' => EmployeeProfile::employmentTypeOptions(),
        ]);
    }
}
