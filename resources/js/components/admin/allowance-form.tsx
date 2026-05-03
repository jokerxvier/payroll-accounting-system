import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    index as allowancesIndex,
    store as allowancesStore,
    update as allowancesUpdate,
} from '@/routes/admin/allowances';
import type { AllowanceRow } from '@/types';

type Mode = { kind: 'create' } | { kind: 'edit'; allowance: AllowanceRow };

interface AllowanceFormProps {
    mode: Mode;
}

interface FormShape {
    code: string;
    name: string;
    is_taxable: boolean;
    is_de_minimis: boolean;
    /** Centavos integer; null means "no cap" (only valid when is_de_minimis = false). */
    de_minimis_cap_centavos: number | null;
    default_amount_centavos: number | null;
    is_active: boolean;
    notes: string;
    [key: string]: string | boolean | number | null;
}

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            code: '',
            name: '',
            is_taxable: true,
            is_de_minimis: false,
            de_minimis_cap_centavos: null,
            default_amount_centavos: null,
            is_active: true,
            notes: '',
        };
    }

    const row = mode.allowance;

    return {
        code: row.code,
        name: row.name,
        is_taxable: row.is_taxable,
        is_de_minimis: row.is_de_minimis,
        de_minimis_cap_centavos: row.de_minimis_cap_centavos,
        default_amount_centavos: row.default_amount_centavos,
        is_active: row.is_active,
        notes: row.notes ?? '',
    };
}

function centavosToPesos(centavos: number | null): string {
    if (centavos === null) {
        return '';
    }

    return (centavos / 100).toFixed(2);
}

function pesosToCentavosOrNull(input: string): number | null {
    const cleaned = input.trim();

    if (cleaned === '') {
        return null;
    }

    const parsed = Number(cleaned);

    if (Number.isNaN(parsed) || parsed < 0) {
        return null;
    }

    return Math.round(parsed * 100);
}

export function AllowanceForm({ mode }: AllowanceFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));

    // Local string state for the two money inputs so the user can type
    // freely (intermediate states like "12." or "" without the controlled
    // input snapping back to the centavos integer).
    const [capStr, setCapStr] = useState(() =>
        centavosToPesos(form.data.de_minimis_cap_centavos),
    );
    const [defaultStr, setDefaultStr] = useState(() =>
        centavosToPesos(form.data.default_amount_centavos),
    );

    // When de-minimis flips OFF, blank the cap so the FormRequest sees
    // null (the validator only enforces required when is_de_minimis = true).
    useEffect(() => {
        if (!form.data.is_de_minimis) {
            // eslint-disable-next-line react-hooks/set-state-in-effect
            setCapStr('');
            form.setData('de_minimis_cap_centavos', null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.is_de_minimis]);

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (mode.kind === 'create') {
            form.post(allowancesStore().url, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Allowance '${form.data.code}' created.`);
                },
            });

            return;
        }

        form.patch(allowancesUpdate({ allowance: mode.allowance.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Allowance '${form.data.code}' updated.`);
            },
        });
    };

    const isEdit = mode.kind === 'edit';

    return (
        <form onSubmit={handleSubmit} className="space-y-6" noValidate>
            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Identity
                    </CardTitle>
                    <CardDescription>
                        A short stable code plus a human-readable name.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                type="text"
                                maxLength={32}
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                placeholder="e.g. RICE"
                                className="font-mono"
                                required
                            />
                            <InputError message={form.errors.code} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                type="text"
                                maxLength={120}
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Rice subsidy"
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Tax behavior
                    </CardTitle>
                    <CardDescription>
                        Controls how the engine treats this allowance when
                        computing BIR withholding.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                        <div className="space-y-0.5">
                            <Label htmlFor="is_taxable">Taxable</Label>
                            <p className="text-xs text-muted-foreground">
                                When ON, the full allowance amount is added to
                                taxable income.
                            </p>
                        </div>
                        <Switch
                            id="is_taxable"
                            checked={form.data.is_taxable}
                            onCheckedChange={(checked) =>
                                form.setData('is_taxable', checked)
                            }
                        />
                    </div>
                    <InputError message={form.errors.is_taxable} />

                    <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                        <div className="space-y-0.5">
                            <Label htmlFor="is_de_minimis">De-minimis</Label>
                            <p className="text-xs text-muted-foreground">
                                BIR-recognized de-minimis benefits are
                                non-taxable up to a configured cap. Excess above
                                the cap becomes taxable.
                            </p>
                        </div>
                        <Switch
                            id="is_de_minimis"
                            checked={form.data.is_de_minimis}
                            onCheckedChange={(checked) =>
                                form.setData('is_de_minimis', checked)
                            }
                        />
                    </div>
                    <InputError message={form.errors.is_de_minimis} />

                    <div className="grid gap-2">
                        <Label
                            htmlFor="de_minimis_cap_centavos"
                            className={
                                form.data.is_de_minimis
                                    ? undefined
                                    : 'text-muted-foreground'
                            }
                        >
                            De-minimis cap (per period)
                        </Label>
                        <div className="relative">
                            <span
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                            >
                                ₱
                            </span>
                            <Input
                                id="de_minimis_cap_centavos"
                                inputMode="decimal"
                                pattern="[0-9]*\.?[0-9]*"
                                value={capStr}
                                disabled={!form.data.is_de_minimis}
                                required={form.data.is_de_minimis}
                                onChange={(e) => {
                                    const next = e.target.value;
                                    setCapStr(next);
                                    form.setData(
                                        'de_minimis_cap_centavos',
                                        pesosToCentavosOrNull(next),
                                    );
                                }}
                                onBlur={() => {
                                    setCapStr(
                                        centavosToPesos(
                                            form.data.de_minimis_cap_centavos,
                                        ),
                                    );
                                }}
                                placeholder={
                                    form.data.is_de_minimis
                                        ? 'e.g. 2000.00'
                                        : 'Enable de-minimis to set a cap'
                                }
                                className="pl-7 text-right tabular-nums"
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {form.data.is_de_minimis
                                ? 'Required. Per-period peso ceiling above which the excess becomes taxable.'
                                : 'Disabled — only relevant when de-minimis is ON.'}
                        </p>
                        <InputError
                            message={form.errors.de_minimis_cap_centavos}
                        />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Defaults
                    </CardTitle>
                    <CardDescription>
                        Optional hint surfaced when subscribing an employee to
                        this allowance.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="default_amount_centavos">
                            Default amount (optional)
                        </Label>
                        <div className="relative">
                            <span
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                            >
                                ₱
                            </span>
                            <Input
                                id="default_amount_centavos"
                                inputMode="decimal"
                                pattern="[0-9]*\.?[0-9]*"
                                value={defaultStr}
                                onChange={(e) => {
                                    const next = e.target.value;
                                    setDefaultStr(next);
                                    form.setData(
                                        'default_amount_centavos',
                                        pesosToCentavosOrNull(next),
                                    );
                                }}
                                onBlur={() => {
                                    setDefaultStr(
                                        centavosToPesos(
                                            form.data.default_amount_centavos,
                                        ),
                                    );
                                }}
                                placeholder="Optional"
                                className="pl-7 text-right tabular-nums"
                            />
                        </div>
                        <InputError
                            message={form.errors.default_amount_centavos}
                        />
                    </div>

                    <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                        <div className="space-y-0.5">
                            <Label htmlFor="is_active">Active</Label>
                            <p className="text-xs text-muted-foreground">
                                Inactive allowances are hidden from new
                                subscription forms but stay readable for
                                historical payroll runs.
                            </p>
                        </div>
                        <Switch
                            id="is_active"
                            checked={form.data.is_active}
                            onCheckedChange={(checked) =>
                                form.setData('is_active', checked)
                            }
                        />
                    </div>
                    <InputError message={form.errors.is_active} />

                    <div className="grid gap-2">
                        <Label htmlFor="notes">Notes</Label>
                        <textarea
                            id="notes"
                            rows={3}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                            placeholder="Optional context — source citation, reason for adding, etc."
                            className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={form.errors.notes} />
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center justify-end gap-2">
                <Button asChild variant="outline" type="button">
                    <Link href={allowancesIndex().url}>Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || (isEdit && !form.isDirty)}
                >
                    {form.processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save changes'
                          : 'Create allowance'}
                </Button>
            </div>
        </form>
    );
}
