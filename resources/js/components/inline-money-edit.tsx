import { Check, Pencil, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Money } from '@/components/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

interface InlineMoneyEditProps {
    /**
     * The current canonical value, in centavos. Treated as the source of truth —
     * when the parent re-renders with a new prop after a successful save, the
     * component reconciles to the new value.
     */
    valueCentavos: number;
    /**
     * Called with the parsed centavos value when the user explicitly confirms
     * (clicks ✓ or presses Enter). The caller performs the actual write.
     *
     * Optimistic UI contract:
     * - If `onSave` returns a Promise, the component renders the draft value
     *   while the promise is pending.
     * - If the promise rejects, the component reverts to the original
     *   `valueCentavos`.
     * - Synchronous (`void`) returns are also supported.
     */
    onSave: (newCentavos: number) => Promise<void> | void;
    /** Accessible label, used to build the trigger's `aria-label`. */
    label?: string;
    /** When true, render the value as plain `<Money>` with no click affordance. */
    disabled?: boolean;
    className?: string;
}

const ERROR_DISMISS_MS = 3000;

function centavosToInputString(centavos: number): string {
    return (centavos / 100).toFixed(2);
}

function parseInputToCentavos(raw: string): number | null {
    const trimmed = raw.trim();

    if (trimmed === '') {
        return null;
    }

    const parsed = Number(trimmed);

    if (Number.isNaN(parsed) || parsed < 0) {
        return null;
    }

    return Math.round(parsed * 100);
}

export function InlineMoneyEdit({
    valueCentavos,
    onSave,
    label,
    disabled = false,
    className,
}: InlineMoneyEditProps) {
    const [editing, setEditing] = useState(false);
    const [inputValue, setInputValue] = useState(() =>
        centavosToInputString(valueCentavos),
    );
    const [draftCentavos, setDraftCentavos] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const inputRef = useRef<HTMLInputElement | null>(null);

    useEffect(() => {
        if (error === null) {
            return;
        }

        const id = window.setTimeout(() => setError(null), ERROR_DISMISS_MS);

        return () => window.clearTimeout(id);
    }, [error]);

    // The displayed value reconciles itself: the draft only "wins" while
    // it differs from the canonical prop. Once the parent re-renders with
    // the saved value, the prop takes over.
    const displayCentavos =
        draftCentavos !== null && draftCentavos !== valueCentavos
            ? draftCentavos
            : valueCentavos;

    const enterEditMode = () => {
        setInputValue(centavosToInputString(valueCentavos));
        setError(null);
        setEditing(true);
    };

    const cancelEdit = () => {
        setEditing(false);
        setError(null);
    };

    const confirmEdit = async () => {
        const parsed = parseInputToCentavos(inputValue);

        if (parsed === null) {
            setError('Enter a valid amount.');

            return;
        }

        if (parsed === valueCentavos) {
            setEditing(false);

            return;
        }

        setDraftCentavos(parsed);
        setEditing(false);
        setSaving(true);

        try {
            await onSave(parsed);
        } catch {
            setDraftCentavos(null);
        } finally {
            setSaving(false);
        }
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            void confirmEdit();

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            cancelEdit();
        }
    };

    if (disabled) {
        return (
            <span className={cn('inline-flex items-baseline', className)}>
                <Money amount={displayCentavos / 100} />
            </span>
        );
    }

    if (editing) {
        return (
            <span
                className={cn(
                    'inline-flex flex-col items-start gap-1',
                    className,
                )}
                aria-busy={saving || undefined}
            >
                <span className="inline-flex items-center gap-1">
                    <span
                        aria-hidden="true"
                        className="text-sm text-muted-foreground"
                    >
                        ₱
                    </span>
                    <Input
                        ref={inputRef}
                        inputMode="decimal"
                        pattern="[0-9]*\.?[0-9]*"
                        autoFocus
                        aria-label={label ?? 'Amount'}
                        value={inputValue}
                        onChange={(event) => setInputValue(event.target.value)}
                        onKeyDown={handleKeyDown}
                        className="h-8 w-32 text-right tabular-nums"
                    />
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Confirm edit"
                        onClick={() => {
                            void confirmEdit();
                        }}
                        className="h-8 w-8 text-success hover:text-success"
                    >
                        <Check className="h-4 w-4" />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Cancel edit"
                        onClick={cancelEdit}
                        className="h-8 w-8 text-muted-foreground"
                    >
                        <X className="h-4 w-4" />
                    </Button>
                </span>
                {error !== null && (
                    <span role="alert" className="text-xs text-destructive">
                        {error}
                    </span>
                )}
            </span>
        );
    }

    return (
        <span
            className={cn('inline-flex flex-col items-start gap-1', className)}
            aria-busy={saving || undefined}
        >
            <button
                type="button"
                onClick={enterEditMode}
                aria-label={label ? `Edit ${label}` : 'Edit value'}
                className={cn(
                    '-mx-1 inline-flex items-baseline gap-1.5 rounded px-1 text-left',
                    'hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    'transition-colors',
                )}
            >
                <Money amount={displayCentavos / 100} />
                <Pencil
                    aria-hidden="true"
                    className="h-3 w-3 text-muted-foreground"
                />
            </button>
            {error !== null && (
                <span role="alert" className="text-xs text-destructive">
                    {error}
                </span>
            )}
        </span>
    );
}
