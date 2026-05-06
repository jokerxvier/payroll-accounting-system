<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Pas\EmployeeProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Parses the bulk-edit template into a structured per-row diff against
 * the live state. Does NOT write anything — `parsed()` returns a
 * dataset the controller renders for preview, and the confirm endpoint
 * applies it inside a single transaction.
 *
 * Each parsed row reports: the lookup key (`lms_staff_id`), the source
 * row from the spreadsheet, the matching live profile (or null), the
 * field-level changeset (only fields that differ are listed), and any
 * row-level errors. A row with errors is skipped on confirm; a row
 * with no changes is also skipped (no-op).
 */
final class EmployeeBulkEditImport implements ToCollection, WithHeadingRow
{
    use Importable;

    /**
     * @var array<int, array{
     *     row_number: int,
     *     lms_staff_id: int|null,
     *     profile_exists: bool,
     *     full_name: string|null,
     *     changes: array<string, array{from: mixed, to: mixed}>,
     *     errors: array<int, string>,
     * }>
     */
    private array $parsed = [];

    public function collection(Collection $rows): void
    {
        $this->parsed = [];
        $rowNumber = 1; // heading is row 1; data starts at row 2

        foreach ($rows as $raw) {
            $rowNumber++;

            $entry = [
                'row_number' => $rowNumber,
                'lms_staff_id' => null,
                'profile_exists' => false,
                'full_name' => $raw['full_name_read_only'] ?? null,
                'changes' => [],
                'errors' => [],
            ];

            // Required: lms_staff_id (the join key)
            $rawStaffId = $raw['lms_staff_id'] ?? null;
            if ($rawStaffId === null || $rawStaffId === '') {
                $entry['errors'][] = 'lms_staff_id is required.';
                $this->parsed[] = $entry;

                continue;
            }
            if (! is_numeric($rawStaffId) || (int) $rawStaffId <= 0) {
                $entry['errors'][] = 'lms_staff_id must be a positive integer.';
                $this->parsed[] = $entry;

                continue;
            }

            $staffId = (int) $rawStaffId;
            $entry['lms_staff_id'] = $staffId;

            $profile = EmployeeProfile::query()
                ->where('lms_staff_id', $staffId)
                ->first();

            if ($profile === null) {
                $entry['errors'][] = sprintf(
                    'No employee profile exists for lms_staff_id %d. Use the LMS to onboard staff first.',
                    $staffId,
                );
                $this->parsed[] = $entry;

                continue;
            }
            $entry['profile_exists'] = true;

            // Compute field-level diffs. Each helper validates the cell
            // and records into $entry['changes'] / $entry['errors'].
            $this->diffString(
                $entry,
                'employment_classification',
                $raw['employment_classification'] ?? null,
                $profile->employment_classification,
                allowedValues: EmployeeProfile::EMPLOYMENT_CLASSIFICATIONS,
            );
            $this->diffString(
                $entry,
                'pay_frequency',
                $raw['pay_frequency'] ?? null,
                $profile->pay_frequency,
                allowedValues: ['monthly', 'semi_monthly'],
            );
            $this->diffInt(
                $entry,
                'basic_salary_centavos',
                $raw['basic_salary_centavos'] ?? null,
                $profile->basic_salary_centavos,
                min: 0,
                max: 999_999_999_999,
            );
            $this->diffString(
                $entry,
                'tax_status',
                $raw['tax_status'] ?? null,
                $profile->tax_status,
            );
            $this->diffDate(
                $entry,
                'date_hired',
                $raw['date_hired'] ?? null,
                $profile->date_hired?->toDateString(),
            );
            $this->diffDate(
                $entry,
                'date_terminated',
                $raw['date_terminated'] ?? null,
                $profile->date_terminated?->toDateString(),
            );
            $this->diffBool(
                $entry,
                'is_active',
                $raw['is_active'] ?? null,
                $profile->is_active,
            );

            $this->parsed[] = $entry;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parsed(): array
    {
        return $this->parsed;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>|null  $allowedValues
     */
    private function diffString(array &$entry, string $field, mixed $rawValue, ?string $current, ?array $allowedValues = null): void
    {
        if ($rawValue === null || $rawValue === '') {
            return; // blank cell = no change
        }
        $value = (string) $rawValue;
        if ($allowedValues !== null && ! in_array($value, $allowedValues, true)) {
            $entry['errors'][] = sprintf(
                '%s must be one of: %s',
                $field,
                implode(', ', $allowedValues),
            );

            return;
        }
        if ($value !== $current) {
            $entry['changes'][$field] = ['from' => $current, 'to' => $value];
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function diffInt(array &$entry, string $field, mixed $rawValue, ?int $current, int $min, int $max): void
    {
        if ($rawValue === null || $rawValue === '') {
            return;
        }
        if (! is_numeric($rawValue)) {
            $entry['errors'][] = sprintf('%s must be an integer.', $field);

            return;
        }
        $value = (int) $rawValue;
        if ($value < $min || $value > $max) {
            $entry['errors'][] = sprintf('%s out of range (%d–%d).', $field, $min, $max);

            return;
        }
        if ($value !== $current) {
            $entry['changes'][$field] = ['from' => $current, 'to' => $value];
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function diffDate(array &$entry, string $field, mixed $rawValue, ?string $currentDate): void
    {
        if ($rawValue === null || $rawValue === '') {
            return;
        }
        $value = (string) $rawValue;
        try {
            $parsed = CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            $entry['errors'][] = sprintf(
                '%s could not be parsed as a date (try YYYY-MM-DD).',
                $field,
            );

            return;
        }
        if ($parsed !== $currentDate) {
            $entry['changes'][$field] = ['from' => $currentDate, 'to' => $parsed];
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function diffBool(array &$entry, string $field, mixed $rawValue, bool $current): void
    {
        if ($rawValue === null || $rawValue === '') {
            return;
        }
        $truthy = ['1', 1, true, 'true', 'TRUE', 'yes', 'YES'];
        $falsy = ['0', 0, false, 'false', 'FALSE', 'no', 'NO'];
        if (in_array($rawValue, $truthy, true)) {
            $value = true;
        } elseif (in_array($rawValue, $falsy, true)) {
            $value = false;
        } else {
            $entry['errors'][] = sprintf('%s must be 1 or 0 (true/false).', $field);

            return;
        }
        if ($value !== $current) {
            $entry['changes'][$field] = ['from' => $current, 'to' => $value];
        }
    }
}
