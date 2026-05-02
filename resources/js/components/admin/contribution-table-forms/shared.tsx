import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * Shared helpers for the four algorithm-specific subforms.
 *
 * The unit-conversion contract:
 *   - Money fields display as pesos (`123.45`) and emit centavos (`int`).
 *   - Percentage fields display as percentages (`5.00`) and emit basis
 *     points (`int`, where `100` = `1%`, `10000` = `100%`).
 *
 * The on-screen string representation is always derived from the underlying
 * integer value at render time. Inputs are uncontrolled-feeling but actually
 * controlled: parent owns the integer source of truth, subform owns the text.
 */

export function centavosToPesoString(centavos: number): string {
    return (centavos / 100).toFixed(2);
}

export function pesoStringToCentavos(input: string): number {
    const cleaned = input.trim();

    if (cleaned === '') {
        return 0;
    }

    const parsed = Number(cleaned);

    if (Number.isNaN(parsed) || parsed < 0) {
        return 0;
    }

    return Math.round(parsed * 100);
}

export function basisPointsToPercentString(bp: number): string {
    return (bp / 100).toFixed(2);
}

export function percentStringToBasisPoints(input: string): number {
    const cleaned = input.trim();

    if (cleaned === '') {
        return 0;
    }

    const parsed = Number(cleaned);

    if (Number.isNaN(parsed) || parsed < 0) {
        return 0;
    }

    return Math.round(parsed * 100);
}

interface MoneyInputProps {
    id?: string;
    label?: string;
    /** Integer centavos. */
    value: number;
    onChange: (centavos: number) => void;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    /** Hide the prefixed peso glyph (e.g. for inline table cells). */
    showSymbol?: boolean;
}

export function MoneyInput({
    id,
    label,
    value,
    onChange,
    placeholder,
    disabled,
    className,
    showSymbol = true,
}: MoneyInputProps) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            {label && <Label htmlFor={id}>{label}</Label>}
            <div className="relative">
                {showSymbol && (
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                    >
                        ₱
                    </span>
                )}
                <Input
                    id={id}
                    inputMode="decimal"
                    pattern="[0-9]*\.?[0-9]*"
                    value={centavosToPesoString(value)}
                    placeholder={placeholder}
                    disabled={disabled}
                    onChange={(e) =>
                        onChange(pesoStringToCentavos(e.target.value))
                    }
                    className={cn(
                        'text-right tabular-nums',
                        showSymbol && 'pl-7',
                    )}
                />
            </div>
        </div>
    );
}

interface PercentInputProps {
    id?: string;
    label?: string;
    /** Integer basis points. */
    value: number;
    onChange: (bp: number) => void;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
}

export function PercentInput({
    id,
    label,
    value,
    onChange,
    placeholder,
    disabled,
    className,
}: PercentInputProps) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            {label && <Label htmlFor={id}>{label}</Label>}
            <div className="relative">
                <Input
                    id={id}
                    inputMode="decimal"
                    pattern="[0-9]*\.?[0-9]*"
                    value={basisPointsToPercentString(value)}
                    placeholder={placeholder}
                    disabled={disabled}
                    onChange={(e) =>
                        onChange(percentStringToBasisPoints(e.target.value))
                    }
                    className="pr-7 text-right tabular-nums"
                />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                >
                    %
                </span>
            </div>
        </div>
    );
}

/**
 * Generate a stable client-side key for dynamic-row tables. Used to keep
 * React's reconciliation honest when adding/removing rows in the bracket and
 * salary-band forms.
 */
export function newRowKey(): string {
    return `row-${Math.random().toString(36).slice(2)}-${Date.now()}`;
}
