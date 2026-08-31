<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How a payroll line is named on a document a member of staff reads.
 *
 * Audit lines are stored with the label the rate table gave them —
 * "SSS Contribution (2025) (employee)". The trailing "(employee)" /
 * "(employer)" is unambiguous to a payroll officer reconciling a run and
 * redundant on a payslip, where the heading above the line already says whose
 * money it is.
 *
 * Applied at the presentation edge, never to the stored line: the audit trail
 * keeps the label it was computed under.
 *
 * Both the PDF template and the on-screen payslip call this, because a figure
 * that is named one way on the screen and another way on the printout is the
 * kind of difference a member of staff brings to HR.
 */
final class PayslipLabel
{
    public static function humanise(string $label): string
    {
        return trim((string) preg_replace(
            '/\s*\((?:employee|employer)\)\s*$/i',
            '',
            $label,
        ));
    }

    /**
     * The same, over a list of audit lines.
     *
     * Audit lines come out of a JSON column, so the label is read defensively
     * rather than assumed: a line that somehow lost its label should render
     * blank, not fatal a payslip.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function humaniseLines(array $lines): array
    {
        return array_map(
            static function (array $line): array {
                $label = $line['label'] ?? '';
                $line['label'] = is_string($label) ? self::humanise($label) : '';

                return $line;
            },
            $lines,
        );
    }
}
