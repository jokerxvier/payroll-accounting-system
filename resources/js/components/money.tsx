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

    const formatted = new Intl.NumberFormat('en-PH', {
        style: showSymbol ? 'currency' : 'decimal',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Math.abs(value));

    const isNegative = value < 0;
    const isPositive = value > 0;

    return (
        <span
            className={cn(
                'tabular-nums',
                signed && isNegative && 'text-destructive',
                signed && isPositive && 'text-success',
                className,
            )}
        >
            {signed && isNegative ? `−${formatted}` : formatted}
        </span>
    );
}
