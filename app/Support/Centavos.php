<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Integer-only arithmetic and formatting for centavo amounts on reports.
 *
 * Every figure on a financial statement is an integer number of centavos, and
 * the two things a report does with one — print it, and compare it to another
 * — are both places a float creeps in unnoticed: `$centavos / 100` to print,
 * `$a / $b * 100` for a percentage. Neither is needed.
 */
final class Centavos
{
    /** Pesos as a plain decimal string: `-123456` is `-1234.56`. */
    public static function decimal(int $centavos): string
    {
        $magnitude = abs($centavos);

        return sprintf(
            '%s%d.%02d',
            $centavos < 0 ? '-' : '',
            intdiv($magnitude, 100),
            $magnitude % 100,
        );
    }

    /**
     * Pesos as printed on a statement: `-123456789` is `(1,234,567.89)`.
     *
     * Thousands separated, and a negative in brackets — the way a contra
     * account or a loss is read on a statement. A minus sign is easy to lose
     * in a column of figures; brackets are not.
     */
    public static function formatted(int $centavos, string $symbol = ''): string
    {
        $magnitude = abs($centavos);
        $figure = $symbol.number_format(intdiv($magnitude, 100)).sprintf('.%02d', $magnitude % 100);

        return $centavos < 0 ? "({$figure})" : $figure;
    }

    /**
     * How far `$current` moved from `$comparison`, in basis points of the
     * comparison (12.5% is 1250), rounded half-up.
     *
     * Null when the comparison is zero: growth from nothing has no
     * percentage, and printing 0% or 100% for it would be inventing one.
     *
     * Divided by the comparison's magnitude, so the sign is the direction of
     * the change even when the comparison was a loss — a loss of 100 that
     * became a loss of 50 is +50%, not −50%.
     */
    public static function changeBasisPoints(int $current, int $comparison): ?int
    {
        if ($comparison === 0) {
            return null;
        }

        $change = $current - $comparison;
        $denominator = abs($comparison);
        $magnitude = intdiv(abs($change) * 20_000 + $denominator, 2 * $denominator);

        return $change < 0 ? -$magnitude : $magnitude;
    }

    /** Basis points as a percentage string: `1250` is `12.50%`. */
    public static function percentFromBasisPoints(?int $basisPoints): string
    {
        return $basisPoints === null
            ? ''
            : self::decimal($basisPoints).'%';
    }
}
