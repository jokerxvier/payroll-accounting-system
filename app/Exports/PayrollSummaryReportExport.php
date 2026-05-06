<?php

declare(strict_types=1);

namespace App\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Excel-rendered version of the payroll summary report (Phase 4 W13).
 * Same row shape as the Inertia page; centavos are converted to pesos
 * with two decimals so the spreadsheet is finance-readable without a
 * second formatting pass.
 *
 * @phpstan-type SummaryRow array{
 *     run_id: int,
 *     status: string,
 *     pay_period_code: string|null,
 *     pay_period_start: string|null,
 *     pay_period_end: string|null,
 *     employee_count: int,
 *     gross_pay_centavos: int,
 *     total_employee_deductions_centavos: int,
 *     total_employer_contributions_centavos: int,
 *     total_net_pay_centavos: int
 * }
 */
final class PayrollSummaryReportExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            sprintf(
                'Payroll summary · %s → %s',
                $this->from->toDateString(),
                $this->to->toDateString(),
            ),
        ];
    }

    public function collection(): Collection
    {
        $header = [
            'Run ID',
            'Status',
            'Period',
            'Period Start',
            'Period End',
            'Employees',
            'Gross Pay',
            'Employee Deductions',
            'Employer Contributions',
            'Net Pay',
        ];

        $rows = collect($this->rows)->map(fn (array $r): array => [
            $r['run_id'],
            $r['status'],
            $r['pay_period_code'] ?? '',
            $r['pay_period_start'] ?? '',
            $r['pay_period_end'] ?? '',
            $r['employee_count'],
            number_format($r['gross_pay_centavos'] / 100, 2, '.', ''),
            number_format($r['total_employee_deductions_centavos'] / 100, 2, '.', ''),
            number_format($r['total_employer_contributions_centavos'] / 100, 2, '.', ''),
            number_format($r['total_net_pay_centavos'] / 100, 2, '.', ''),
        ]);

        // Spacer + totals row at the bottom.
        $totals = [
            'TOTAL',
            '',
            '',
            '',
            '',
            collect($this->rows)->sum('employee_count'),
            number_format(collect($this->rows)->sum('gross_pay_centavos') / 100, 2, '.', ''),
            number_format(collect($this->rows)->sum('total_employee_deductions_centavos') / 100, 2, '.', ''),
            number_format(collect($this->rows)->sum('total_employer_contributions_centavos') / 100, 2, '.', ''),
            number_format(collect($this->rows)->sum('total_net_pay_centavos') / 100, 2, '.', ''),
        ];

        return collect([$header])
            ->concat($rows)
            ->push([])
            ->push($totals);
    }
}
