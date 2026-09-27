import { formatMoney } from '@/lib/format-money';
import { cn } from '@/lib/utils';

/**
 * A figure on a financial statement.
 *
 * Differs from `<Money>` in the one way a statement needs: a negative is
 * printed in brackets. `<Money>` without `signed` prints the magnitude alone,
 * which would show Accumulated Depreciation as an asset of the same size, and
 * with `signed` it colours the figure — and a contra account is not bad news.
 * Brackets are how a statement says "less", and unlike a minus sign they are
 * hard to lose in a column.
 */
export function StatementAmount({
    centavos,
    blankWhenZero = false,
    className,
}: {
    centavos: number;
    /** Print a dash for nil, as the ledger reports do on an account line. */
    blankWhenZero?: boolean;
    className?: string;
}) {
    if (centavos === 0 && blankWhenZero) {
        return <span className="text-muted-foreground">&mdash;</span>;
    }

    const figure = formatMoney(Math.abs(centavos) / 100, {
        showSymbol: false,
    });

    return (
        <span className={cn('whitespace-nowrap tabular-nums', className)}>
            {centavos < 0 ? `(${figure})` : figure}
        </span>
    );
}

/**
 * The change between two columns.
 *
 * Signed by arithmetic — current less comparison — and coloured by whether
 * that is good news, which are different things: salaries rising by ₱1,500
 * is `+1,500.00` and it is not green.
 */
export function StatementVariance({
    centavos,
    favourable,
}: {
    centavos: number | null;
    favourable: boolean | null;
}) {
    if (centavos === null || centavos === 0) {
        return <span className="text-muted-foreground">&mdash;</span>;
    }

    const figure = formatMoney(Math.abs(centavos) / 100, {
        showSymbol: false,
    });

    return (
        <span
            className={cn(
                'whitespace-nowrap tabular-nums',
                favourable === true && 'text-success',
                favourable === false && 'text-destructive',
            )}
        >
            {centavos > 0 ? '+' : '−'}
            {figure}
        </span>
    );
}

/** Basis points as a percentage: 1250 is +12.50%. */
export function StatementVariancePercent({
    basisPoints,
    favourable,
}: {
    basisPoints: number | null;
    favourable: boolean | null;
}) {
    if (basisPoints === null || basisPoints === 0) {
        return <span className="text-muted-foreground">&mdash;</span>;
    }

    const magnitude = Math.abs(basisPoints);
    const whole = Math.floor(magnitude / 100);
    const hundredths = String(magnitude % 100).padStart(2, '0');

    return (
        <span
            className={cn(
                'whitespace-nowrap tabular-nums',
                favourable === true && 'text-success',
                favourable === false && 'text-destructive',
            )}
        >
            {basisPoints > 0 ? '+' : '−'}
            {whole.toLocaleString('en-PH')}.{hundredths}%
        </span>
    );
}
