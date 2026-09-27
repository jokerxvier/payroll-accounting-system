<?php

declare(strict_types=1);

/*
 * Keeps float arithmetic out of the financial statements.
 *
 * Every figure on a statement is an integer number of centavos, and the two
 * things a report does with one are both places a float creeps in unnoticed:
 * `$centavos / 100` to print it, and `$a / $b * 100` for a percentage.
 * `App\Support\Centavos` does both with integers.
 *
 * `PayrollFloatAuditTest` makes the same promise for payroll and scans only
 * payroll. This is that guard over the statement code, including its two PDF
 * views — a Blade file is where a quick `number_format($x / 100, 2)` is most
 * likely to be typed.
 *
 * Deliberately NOT the older ledger reports. The Trial Balance export and PDF
 * divide by 100 to print, and have since Slice 8a; widening this to them
 * means changing them first.
 */

/** @return list<string> */
function statementAuditFiles(): array
{
    // dirname(), not base_path(): the Architecture suite runs without a
    // booted application.
    $root = dirname(__DIR__, 2);

    return array_map(static fn (string $path): string => $root.'/'.$path, [
        'app/Services/Accounting/Reports/FinancialStatementService.php',
        'app/Services/Accounting/Reports/StatementColumnResolver.php',
        'app/Services/Accounting/Reports/StatementColumnSet.php',
        'app/Services/Accounting/Reports/StatementColumn.php',
        'app/Services/Accounting/Reports/StatementSectionMap.php',
        'app/Services/Accounting/Reports/StatementLine.php',
        'app/Services/Accounting/Reports/IncomeStatement.php',
        'app/Services/Accounting/Reports/BalanceSheet.php',
        'app/Services/Accounting/Reports/BalanceSheetColumn.php',
        'app/Support/Centavos.php',
        'app/Exports/BalanceSheetExport.php',
        'app/Exports/IncomeStatementExport.php',
        'resources/views/reports/balance-sheet-pdf.blade.php',
        'resources/views/reports/income-statement-pdf.blade.php',
    ]);
}

it('audits files that exist', function () {
    // A renamed file would otherwise drop out of the audit without a word.
    foreach (statementAuditFiles() as $file) {
        expect(is_file($file))->toBeTrue("Missing from the statement audit: {$file}");
    }
});

it('keeps float arithmetic out of the financial statements', function (string $pattern, string $what) {
    $offenders = [];

    foreach (statementAuditFiles() as $file) {
        foreach (file($file) ?: [] as $number => $line) {
            // A comment may name the thing it forbids.
            if (preg_match('/^\s*(\/\/|\*|\/\*|\{\{--|#)/', $line) === 1) {
                continue;
            }

            if (preg_match($pattern, $line) === 1) {
                $offenders[] = sprintf('  %s:%d  %s', basename($file), $number + 1, trim($line));
            }
        }
    }

    expect($offenders)->toBe([], "{$what} in statement code:\n".implode("\n", $offenders));
})->with([
    'a float cast' => ['/\(float\)|\(double\)/', 'A float cast'],
    'floatval' => ['/\bfloatval\s*\(/', 'floatval()'],
    'round' => ['/(?<![\w>:])round\s*\(/', 'round()'],
    'division by a hundred' => ['/\/\s*100\b/', 'Division by 100'],
    'multiplication by a fraction' => ['/\*\s*0?\.\d+/', 'Multiplication by a fraction'],
]);
