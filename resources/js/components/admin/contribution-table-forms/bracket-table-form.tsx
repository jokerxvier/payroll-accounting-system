import { Plus, Trash2 } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import {
    MoneyInput,
    PercentInput,
    newRowKey,
} from '@/components/admin/contribution-table-forms/shared';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { BracketTableBracket, BracketTableRules } from '@/types';

/**
 * Subform for the BIR-style `bracket_table` algorithm.
 *
 * Outputs:
 * <code>
 * { period_types: { monthly: [...], semi_monthly: [...] } }
 * </code>
 *
 * The two period types are required by the BIR table; if a future
 * regulation adds a third (weekly?) the strategy already accepts arbitrary
 * keys, but the UI is fixed to monthly + semi_monthly for v1.
 */
interface BracketTableFormProps {
    value: Record<string, unknown>;
    onChange: (next: Record<string, unknown>) => void;
    errors?: Record<string, string>;
}

type BracketRow = BracketTableBracket & { key: string };

const PERIOD_TYPES = [
    { id: 'monthly', label: 'Monthly' },
    { id: 'semi_monthly', label: 'Semi-monthly' },
] as const;

type PeriodTypeId = (typeof PERIOD_TYPES)[number]['id'];

function emptyRow(): BracketRow {
    return {
        key: newRowKey(),
        lower: 0,
        upper: 0,
        base_tax: 0,
        excess_rate_bp: 0,
        excess_over: 0,
    };
}

function castRules(value: Record<string, unknown>): BracketTableRules {
    const periodTypes =
        typeof value.period_types === 'object' && value.period_types !== null
            ? (value.period_types as Record<string, BracketTableBracket[]>)
            : {};

    return { period_types: periodTypes };
}

function attachKeys(brackets: BracketTableBracket[] | undefined): BracketRow[] {
    if (!brackets || brackets.length === 0) {
        return [emptyRow()];
    }

    return brackets.map((b) => ({ ...b, key: newRowKey() }));
}

function stripKeys(rows: BracketRow[]): BracketTableBracket[] {
    return rows.map((row) => ({
        lower: row.lower,
        upper: row.upper,
        base_tax: row.base_tax,
        excess_rate_bp: row.excess_rate_bp,
        excess_over: row.excess_over,
    }));
}

export function BracketTableForm({
    value,
    onChange,
    errors,
}: BracketTableFormProps) {
    const initial = useMemo(() => {
        const cast = castRules(value);

        return {
            monthly: attachKeys(cast.period_types.monthly),
            semi_monthly: attachKeys(cast.period_types.semi_monthly),
        };
    }, [value]);

    const [rows, setRows] =
        useState<Record<PeriodTypeId, BracketRow[]>>(initial);

    const emit = (next: Record<PeriodTypeId, BracketRow[]>): void => {
        setRows(next);
        onChange({
            period_types: {
                monthly: stripKeys(next.monthly),
                semi_monthly: stripKeys(next.semi_monthly),
            },
        });
    };

    const updateRow = (
        period: PeriodTypeId,
        index: number,
        patch: Partial<BracketRow>,
    ): void => {
        const next = {
            ...rows,
            [period]: rows[period].map((r, i) =>
                i === index ? { ...r, ...patch } : r,
            ),
        };
        emit(next);
    };

    const addRow = (period: PeriodTypeId): void => {
        emit({ ...rows, [period]: [...rows[period], emptyRow()] });
    };

    const removeRow = (period: PeriodTypeId, index: number): void => {
        const filtered = rows[period].filter((_, i) => i !== index);
        emit({
            ...rows,
            [period]: filtered.length > 0 ? filtered : [emptyRow()],
        });
    };

    const ruleError = errors?.rules;

    return (
        <div className="space-y-6">
            {ruleError && (
                <p className="text-sm text-destructive" role="alert">
                    {ruleError}
                </p>
            )}

            {PERIOD_TYPES.map(({ id, label }) => (
                <section key={id} className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h3 className="font-serif text-base font-semibold">
                            {label} brackets
                        </h3>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => addRow(id)}
                        >
                            <Plus className="mr-1 h-3.5 w-3.5" />
                            Add bracket
                        </Button>
                    </div>

                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className="w-[160px] text-xs tracking-wide text-muted-foreground uppercase">
                                        Lower (₱)
                                    </TableHead>
                                    <TableHead className="w-[200px] text-xs tracking-wide text-muted-foreground uppercase">
                                        Upper (₱)
                                    </TableHead>
                                    <TableHead className="w-[160px] text-xs tracking-wide text-muted-foreground uppercase">
                                        Base tax (₱)
                                    </TableHead>
                                    <TableHead className="w-[140px] text-xs tracking-wide text-muted-foreground uppercase">
                                        Excess rate (%)
                                    </TableHead>
                                    <TableHead className="w-[160px] text-xs tracking-wide text-muted-foreground uppercase">
                                        Excess over (₱)
                                    </TableHead>
                                    <TableHead className="w-[60px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows[id].map((row, index) => (
                                    <TableRow key={row.key}>
                                        <TableCell className="p-2">
                                            <MoneyInput
                                                value={row.lower}
                                                onChange={(v) =>
                                                    updateRow(id, index, {
                                                        lower: v,
                                                    })
                                                }
                                                showSymbol={false}
                                            />
                                        </TableCell>
                                        <TableCell className="p-2">
                                            <UpperCell
                                                value={row.upper}
                                                onChange={(upper) =>
                                                    updateRow(id, index, {
                                                        upper,
                                                    })
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="p-2">
                                            <MoneyInput
                                                value={row.base_tax}
                                                onChange={(v) =>
                                                    updateRow(id, index, {
                                                        base_tax: v,
                                                    })
                                                }
                                                showSymbol={false}
                                            />
                                        </TableCell>
                                        <TableCell className="p-2">
                                            <PercentInput
                                                value={row.excess_rate_bp}
                                                onChange={(v) =>
                                                    updateRow(id, index, {
                                                        excess_rate_bp: v,
                                                    })
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="p-2">
                                            <MoneyInput
                                                value={row.excess_over}
                                                onChange={(v) =>
                                                    updateRow(id, index, {
                                                        excess_over: v,
                                                    })
                                                }
                                                showSymbol={false}
                                            />
                                        </TableCell>
                                        <TableCell className="p-2 text-right">
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Remove ${label.toLowerCase()} bracket ${index + 1}`}
                                                onClick={() =>
                                                    removeRow(id, index)
                                                }
                                                className="h-8 w-8 text-muted-foreground hover:text-destructive"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            ))}
        </div>
    );
}

function UpperCell({
    value,
    onChange,
}: {
    value: number | null;
    onChange: (next: number | null) => void;
}) {
    const switchId = useId();
    const isTopTier = value === null;

    return (
        <div className="space-y-1.5">
            {isTopTier ? (
                <div
                    className={cn(
                        'flex h-9 items-center rounded-md border border-dashed bg-muted/30 px-3',
                        'text-xs text-muted-foreground tabular-nums',
                    )}
                >
                    Top tier
                </div>
            ) : (
                <MoneyInput
                    value={value}
                    onChange={onChange}
                    showSymbol={false}
                />
            )}
            <div className="flex items-center gap-2">
                <Switch
                    id={switchId}
                    checked={isTopTier}
                    onCheckedChange={(checked) => onChange(checked ? null : 0)}
                    aria-label="Top tier"
                />
                <Label
                    className="text-xs font-normal text-muted-foreground"
                    htmlFor={switchId}
                >
                    Top tier (no upper bound)
                </Label>
            </div>
        </div>
    );
}
