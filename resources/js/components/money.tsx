import { formatMoney } from '@/lib/format-money';
import { cn } from '@/lib/utils';

interface MoneyProps {
    amount: number | string;
    currency?: string;
    signed?: boolean;
    showSymbol?: boolean;
    className?: string;
}

export function Money({
    amount,
    currency = 'PHP',
    signed = false,
    showSymbol = true,
    className,
}: MoneyProps) {
    const value = typeof amount === 'string' ? Number(amount) : amount;

    // Through the shared formatter, so a figure in a card and the same figure
    // in a chart tooltip cannot be spelled differently.
    const formatted = formatMoney(value, { showSymbol, currency });

    const isNegative = value < 0;
    const isPositive = value > 0;

    return (
        <span
            className={cn(
                // Never wrapped: a figure broken across two lines is always
                // wrong, and a signed one breaks after the minus, which reads
                // as a stray dash above a positive number.
                'whitespace-nowrap tabular-nums',
                signed && isNegative && 'text-destructive',
                signed && isPositive && 'text-success',
                className,
            )}
        >
            {signed && isNegative ? `−${formatted}` : formatted}
        </span>
    );
}
