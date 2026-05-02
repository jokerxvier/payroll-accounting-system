<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Models\Lms\Staff;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Payroll profile for an LMS staff record.
 *
 * Bridges to App\Models\Lms\Staff (sm_staffs.id) via the `lms_staff_id` column.
 * Identity (name, email, role, department) is read from the LMS connection;
 * payroll-specific fields (salary, statutory IDs, bank, classification) live here.
 *
 * Money lives in `basic_salary_centavos` as an integer count of PHP centavos.
 * The Money value object (Phase 2) will wrap reads/writes at the service layer;
 * for Slice B the column is exposed as a raw int.
 *
 * Sensitive government IDs and bank account numbers use the Laravel `encrypted`
 * cast so plaintext never lands in the DB.
 */
final class EmployeeProfile extends Model
{
    use HasFactory;

    public const EMPLOYMENT_CLASSIFICATIONS = ['regular', 'probationary', 'contractual', 'part_time'];

    protected $table = 'pas_employee_profiles';

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function employmentTypeOptions(): array
    {
        return array_map(
            fn (string $v): array => [
                'value' => $v,
                'label' => ucwords(str_replace('_', ' ', $v)),
            ],
            self::EMPLOYMENT_CLASSIFICATIONS,
        );
    }

    /**
     * Mass-assignable attributes. Sensitive encrypted fields are intentionally
     * included here because they pass through validated FormRequests; the
     * encrypted cast keeps them ciphertext-at-rest regardless.
     */
    protected $fillable = [
        'lms_staff_id',
        'employment_classification',
        'pay_frequency',
        'basic_salary_centavos',
        'tax_status',
        'tin',
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'date_hired',
        'date_terminated',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'lms_staff_id' => 'integer',
            'basic_salary_centavos' => 'integer',
            'is_active' => 'boolean',
            'date_hired' => 'date',
            'date_terminated' => 'date',
            // Sensitive fields — encrypted at rest.
            'tin' => 'encrypted',
            'sss_number' => 'encrypted',
            'philhealth_number' => 'encrypted',
            'pagibig_number' => 'encrypted',
            'bank_account_number' => 'encrypted',
        ];
    }

    /**
     * LMS staff record this profile bridges to.
     *
     * NOTE: this is a manual accessor rather than a BelongsTo relation because the
     * target model (App\Models\Lms\Staff) lives on the `lms` connection while this
     * model lives on the default `mysql` connection. Eloquent's BelongsTo issues a
     * single SQL query against the parent's connection — that breaks under a future
     * physical split of the two databases. Resolving via a dedicated query against
     * the LMS connection is correct in both the shared-DB and split-DB topologies.
     *
     * Cached on the instance so repeated accesses don't re-query.
     */
    public function getLmsStaffAttribute(): ?Staff
    {
        if ($this->lms_staff_id === null) {
            return null;
        }

        return $this->memoizedLmsStaff ??= Staff::query()->find($this->lms_staff_id);
    }

    private ?Staff $memoizedLmsStaff = null;
}
