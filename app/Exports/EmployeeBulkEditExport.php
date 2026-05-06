<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Bulk-edit template for employee profiles. Produces one row per active
 * employee with the editable payroll-owned fields. The lms_staff_id is
 * the join key — admins must not change it. full_name is included as
 * read-only context so the editor can recognise rows without consulting
 * the LMS.
 *
 * Encrypted fields (TIN / SSS / PhilHealth / Pag-IBIG / bank account
 * number) are intentionally OMITTED from the bulk template — a bulk
 * Excel sheet is the wrong vehicle for distributing decrypted PII at
 * rest. Single-employee edits via the existing edit sheet stay the
 * canonical path for those fields.
 */
final class EmployeeBulkEditExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'lms_staff_id',
            'full_name (read-only)',
            'employment_classification',
            'pay_frequency',
            'basic_salary_centavos',
            'tax_status',
            'date_hired',
            'date_terminated',
            'is_active',
        ];
    }

    public function collection()
    {
        // Eager-loaded LMS staff names keyed by id, so the template
        // renders with full_name beside each profile without N+1.
        $staffNames = Staff::query()
            ->whereIn('id', EmployeeProfile::query()->pluck('lms_staff_id'))
            ->pluck('full_name', 'id');

        return EmployeeProfile::query()
            ->orderBy('lms_staff_id')
            ->get()
            ->map(fn (EmployeeProfile $p): array => [
                'lms_staff_id' => $p->lms_staff_id,
                'full_name' => $staffNames->get($p->lms_staff_id) ?? '(unknown staff)',
                'employment_classification' => $p->employment_classification,
                'pay_frequency' => $p->pay_frequency,
                'basic_salary_centavos' => $p->basic_salary_centavos,
                'tax_status' => $p->tax_status,
                'date_hired' => $p->date_hired?->toDateString(),
                'date_terminated' => $p->date_terminated?->toDateString(),
                'is_active' => $p->is_active ? 1 : 0,
            ]);
    }
}
