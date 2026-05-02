import { Plus, Trash2 } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import {
    MoneyInput,
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
import type { SalaryBand, SalaryBandRules } from '@/types';

/**
 * Subform for the SSS-style `salary_band` algorithm.
 *
 * Outputs `{ bands: [...] }` where each band carries the precomputed
 * employee/employer/aux shares for that contribution_base. SSS publishes
 * 61 bands; the table is rendered dense (no per-row Card) so the full set
 * stays scannable in a single viewport.
 */
interface SalaryBandFormProps {
    value: Record<string, unknown>;
    onChange: (next: Record<string, unknown>) => void;
    errors?: Record<string, string>;
}

type BandRow = SalaryBand & { key: string };

function emptyRow(): BandRow {
    return {
        key: newRowKey(),
        lower: 0,
        upper: 0,
        contribution_base: 0,
        employee_share: 0,
        employer_share: 0,
        employer_aux_share: 0,
        employee_aux_share: 0,
    };
}

function castRules(value: Record<string, unknown>): SalaryBandRules {
    return {
        bands: Array.isArray(value.bands) ? (value.bands as SalaryBand[]) : [],
    };
}

function attachKeys(bands: SalaryBand[]): BandRow[] {
    if (bands.length === 0) {
        return [emptyRow()];
    }

    return bands.map((b) => ({ ...b, key: newRowKey() }));
}

function stripKeys(rows: BandRow[]): SalaryBand[] {
    return rows.map((row) => ({
        lower: row.lower,
        upper: row.upper,
        contribution_base: row.contribution_base,
        employee_share: row.employee_share,
        employer_share: row.employer_share,
        employer_aux_share: row.employer_aux_share,
        employee_aux_share: row.employee_aux_share,
    }));
}

export function SalaryBandForm({
    value,
    onChange,
    errors,
}: SalaryBandFormProps) {
    const initial = useMemo(() => attachKeys(castRules(value).bands), [value]);
    const [rows, setRows] = useState<BandRow[]>(initial);

    const emit = (next: BandRow[]): void => {
        setRows(next);
        onChange({ bands: stripKeys(next) });
    };

    const updateRow = (index: number, patch: Partial<BandRow>): void => {
        emit(rows.map((r, i) => (i === index ? { ...r, ...patch } : r)));
    };

    const addRow = (): void => {
        emit([...rows, emptyRow()]);
    };

    const removeRow = (index: number): void => {
        const filtered = rows.filter((_, i) => i !== index);
        emit(filtered.length > 0 ? filtered : [emptyRow()]);
    };

    const ruleError = errors?.rules;

    return (
        <div className="space-y-3">
            {ruleError && (
                <p className="text-sm text-destructive" role="alert">
                    {ruleError}
                </p>
            )}

            <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">
                    {rows.length} band{rows.length === 1 ? '' : 's'}. SSS
                    typically lists 61.
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={addRow}
                >
                    <Plus className="mr-1 h-3.5 w-3.5" />
                    Add band
                </Button>
            </div>

            <div className="overflow-x-auto rounded-md border">
                <Table className="text-xs">
                    <TableHeader>
                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                            <TableHead className="w-[120px] text-xs tracking-wide text-muted-foreground uppercase">
                                Lower (₱)
                            </TableHead>
                            <TableHead className="w-[160px] text-xs tracking-wide text-muted-foreground uppercase">
                                Upper (₱)
                            </TableHead>
                            <TableHead className="w-[120px] text-xs tracking-wide text-muted-foreground uppercase">
                                MSC (₱)
                            </TableHead>
                            <TableHead className="w-[120px] text-xs tracking-wide text-muted-foreground uppercase">
                                EE share
                            </TableHead>
                            <TableHead className="w-[120px] text-xs tracking-wide text-muted-foreground uppercase">
                                ER share
                            </TableHead>
                            <TableHead className="w-[120px] text-xs tracking-wide text-muted-foreground uppercase">
                                ER aux (EC)
                            </TableHead>
                            <TableHead className="w-[120px] text-xs tracking-wide text-muted-foreground uppercase">
                                EE aux
                            </TableHead>
                            <TableHead className="w-[60px]" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row, index) => (
                            <TableRow key={row.key}>
                                <TableCell className="p-1.5">
                                    <MoneyInput
                                        value={row.lower}
                                        onChange={(v) =>
                                            updateRow(index, { lower: v })
                                        }
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="p-1.5">
                                    <UpperCell
                                        value={row.upper}
                                        onChange={(upper) =>
                                            updateRow(index, { upper })
                                        }
                                    />
                                </TableCell>
                                <TableCell className="p-1.5">
                                    <MoneyInput
                                        value={row.contribution_base}
                                        onChange={(v) =>
                                            updateRow(index, {
                                                contribution_base: v,
                                            })
                                        }
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="p-1.5">
                                    <MoneyInput
                                        value={row.employee_share}
                                        onChange={(v) =>
                                            updateRow(index, {
                                                employee_share: v,
                                            })
                                        }
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="p-1.5">
                                    <MoneyInput
                                        value={row.employer_share}
                                        onChange={(v) =>
                                            updateRow(index, {
                                                employer_share: v,
                                            })
                                        }
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="p-1.5">
                                    <MoneyInput
                                        value={row.employer_aux_share}
                                        onChange={(v) =>
                                            updateRow(index, {
                                                employer_aux_share: v,
                                            })
                                        }
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="p-1.5">
                                    <MoneyInput
                                        value={row.employee_aux_share}
                                        onChange={(v) =>
                                            updateRow(index, {
                                                employee_aux_share: v,
                                            })
                                        }
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="p-1.5 text-right">
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Remove band ${index + 1}`}
                                        onClick={() => removeRow(index)}
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
        <div className="space-y-1">
            {isTopTier ? (
                <div
                    className={cn(
                        'flex h-9 items-center rounded-md border border-dashed bg-muted/30 px-2',
                        'text-xs text-muted-foreground',
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
                    className="scale-75"
                />
                <Label
                    className="text-[10px] font-normal text-muted-foreground"
                    htmlFor={switchId}
                >
                    Top tier
                </Label>
            </div>
        </div>
    );
}
