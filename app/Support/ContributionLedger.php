<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What actually reached each government agency in an employee's name.
 *
 * Every payslip in the country splits this in two: the employee's share sits
 * under deductions, the employer's share sits in a separate informational
 * block, and the figure the agency actually received — the one that decides a
 * salary loan, a maternity benefit or a Pag-IBIG housing application — appears
 * on neither. Staff are left adding two columns by hand, and most read the
 * employer block as money taken from them.
 *
 * Withholding tax is deliberately absent. It is remitted to the BIR, but it
 * buys no entitlement and is credited to no record the employee can draw on,
 * so listing it beside SSS and PhilHealth would suggest otherwise.
 */
final class ContributionLedger
{
    /**
     * Agency by audit-line code prefix, in the order a payslip lists them.
     *
     * Matched on the prefix rather than the whole code because one agency can
     * post several lines — SSS alone contributes `SSS_EMPLOYEE`,
     * `SSS_EMPLOYER` and `SSS_EMPLOYER_EC`, and Employees' Compensation is
     * still money reaching SSS in the employee's name.
     */
    private const AGENCIES = [
        'SSS' => 'SSS',
        'PHILHEALTH' => 'PhilHealth',
        'PAGIBIG' => 'Pag-IBIG',
    ];

    /**
     * @param  list<array<string, mixed>>  $deductions
     * @param  list<array<string, mixed>>  $employerLines
     * @return list<array{label: string, yours: int, school: int, credited: int}>
     */
    public static function build(array $deductions, array $employerLines): array
    {
        $lines = [...$deductions, ...$employerLines];
        $rows = [];

        foreach (self::AGENCIES as $prefix => $label) {
            $yours = 0;
            $school = 0;

            foreach ($lines as $line) {
                // Audit lines come out of a JSON column: a line missing its
                // code or carrying a non-integer amount is skipped rather
                // than silently counted as zero against an agency.
                $code = $line['code'] ?? null;
                $amount = $line['amount'] ?? null;

                if (! is_string($code) || ! is_int($amount)) {
                    continue;
                }

                if (! str_starts_with($code, $prefix.'_')) {
                    continue;
                }

                // The side comes from the code, not from which argument the
                // line arrived in: a caller that hands us one merged list
                // still gets the split right.
                if (str_contains($code, '_EMPLOYER')) {
                    $school += $amount;
                } else {
                    $yours += $amount;
                }
            }

            // An agency that received nothing this period is left off rather
            // than shown as a row of zeroes, which reads as a missed
            // remittance.
            if ($yours === 0 && $school === 0) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'yours' => $yours,
                'school' => $school,
                'credited' => $yours + $school,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{label: string, yours: int, school: int, credited: int}>  $rows
     */
    public static function total(array $rows): int
    {
        return array_sum(array_column($rows, 'credited'));
    }
}
