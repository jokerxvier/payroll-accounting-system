<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Phase 4 W13 Stage B — Excel render of one employee's payslip timeline.
 * Same row shape the Inertia page consumes; cumulative totals are
 * recomputed from the input rows so the spreadsheet matches the screen.
 */
final class EmployeeHistoryReportExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @param  array<string, mixed>|null  $employee
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, int>>  $ytdByYear
     */
    public function __construct(
        private readonly ?array $employee,
        private readonly array $rows,
        private readonly array $ytdByYear = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        $name = $this->employee['full_name']
            ?? sprintf('Staff #%d', $this->employee['lms_staff_id'] ?? 0);

        return [sprintf('Employee history · %s', $name)];
    }

    public function collection(): Collection
    {
        $columnHeader = [
            'Period',
            'Period Start',
            'Period End',
            'Computed',
            'Gross Pay',
            'Employee Deductions',
            'Net Pay',
            'Cumulative Gross',
            'Cumulative Deductions',
            'Cumulative Net',
        ];

        $body = collect($this->rows)->map(fn (array $r): array => [
            $r['pay_period_code'] ?? '',
            $r['pay_period_start'] ?? '',
            $r['pay_period_end'] ?? '',
            $r['computed_at'] ?? '',
            number_format($r['gross_pay_centavos'] / 100, 2, '.', ''),
            number_format($r['total_employee_deductions_centavos'] / 100, 2, '.', ''),
            number_format($r['net_pay_centavos'] / 100, 2, '.', ''),
            number_format($r['cumulative_gross_centavos'] / 100, 2, '.', ''),
            number_format($r['cumulative_deductions_centavos'] / 100, 2, '.', ''),
            number_format($r['cumulative_net_centavos'] / 100, 2, '.', ''),
        ]);

        $output = collect([$columnHeader])->concat($body);

        // YTD section (Phase 4 W13 Stage C). Groups the same payslips by
        // calendar year for groundwork toward year-end annualization.
        if (count($this->ytdByYear) > 0) {
            $output = $output
                ->push([])
                ->push(['YEAR-TO-DATE'])
                ->push([
                    'Year',
                    'Payslips',
                    'Gross Pay',
                    'Employee Deductions',
                    'Employer Contributions',
                    'Net Pay',
                ]);

            foreach ($this->ytdByYear as $yearTotals) {
                $output = $output->push([
                    $yearTotals['year'],
                    $yearTotals['payslip_count'],
                    number_format($yearTotals['gross_pay_centavos'] / 100, 2, '.', ''),
                    number_format($yearTotals['total_employee_deductions_centavos'] / 100, 2, '.', ''),
                    number_format($yearTotals['total_employer_contributions_centavos'] / 100, 2, '.', ''),
                    number_format($yearTotals['total_net_pay_centavos'] / 100, 2, '.', ''),
                ]);
            }
        }

        return $output;
    }
}
