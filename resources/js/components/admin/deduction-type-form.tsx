import { Link, useForm } from '@inertiajs/react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    index as deductionTypesIndex,
    store as deductionTypesStore,
    update as deductionTypesUpdate,
} from '@/routes/admin/deduction-types';
import type {
    DeductionCalcMethod,
    DeductionSource,
    DeductionTypeRow,
} from '@/types';

type Mode =
    | { kind: 'create' }
    | { kind: 'edit'; deductionType: DeductionTypeRow };

interface DeductionTypeFormProps {
    mode: Mode;
}

interface FormShape {
    code: string;
    name: string;
    calc_method: DeductionCalcMethod;
    source: DeductionSource;
    is_taxable: boolean;
    is_active: boolean;
    notes: string;
    [key: string]: string | boolean;
}

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            code: '',
            name: '',
            calc_method: 'fixed',
            source: 'employee',
            is_taxable: true,
            is_active: true,
            notes: '',
        };
    }

    const row = mode.deductionType;

    return {
        code: row.code,
        name: row.name,
        calc_method: row.calc_method,
        source: row.source,
        is_taxable: row.is_taxable,
        is_active: row.is_active,
        notes: row.notes ?? '',
    };
}

export function DeductionTypeForm({ mode }: DeductionTypeFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (mode.kind === 'create') {
            form.post(deductionTypesStore().url, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(
                        `Deduction type '${form.data.code}' created.`,
                    );
                },
            });

            return;
        }

        form.patch(
            deductionTypesUpdate({ deductionType: mode.deductionType.id }).url,
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(
                        `Deduction type '${form.data.code}' updated.`,
                    );
                },
            },
        );
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
                        A short stable code plus a human-readable name. Code is
                        used in audit logs and exports.
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
                                placeholder="e.g. HMO"
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
                                placeholder="e.g. HMO premium"
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
                        Computation
                    </CardTitle>
                    <CardDescription>
                        How the engine reads each per-employee subscription's
                        amount and which side bears the cost.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="calc_method">Calc method</Label>
                            <Select
                                value={form.data.calc_method}
                                onValueChange={(v) =>
                                    form.setData(
                                        'calc_method',
                                        v as DeductionCalcMethod,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="calc_method"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select method" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="fixed">
                                        Fixed amount
                                    </SelectItem>
                                    <SelectItem value="percent">
                                        Percent of gross
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.calc_method} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="source">Source</Label>
                            <Select
                                value={form.data.source}
                                onValueChange={(v) =>
                                    form.setData('source', v as DeductionSource)
                                }
                            >
                                <SelectTrigger id="source" className="w-full">
                                    <SelectValue placeholder="Select source" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="employee">
                                        Employee only
                                    </SelectItem>
                                    <SelectItem value="employer">
                                        Employer only
                                    </SelectItem>
                                    <SelectItem value="both">
                                        Both employee and employer
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.source} />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Flags
                    </CardTitle>
                    <CardDescription>
                        Tax treatment and catalog visibility.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                        <div className="space-y-0.5">
                            <Label htmlFor="is_taxable">Taxable</Label>
                            <p className="text-xs text-muted-foreground">
                                When ON, this deduction reduces taxable income
                                before BIR withholding is computed.
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
                            <Label htmlFor="is_active">Active</Label>
                            <p className="text-xs text-muted-foreground">
                                Inactive types are hidden from new subscription
                                forms but stay readable for historical payroll
                                runs.
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
                    <Link href={deductionTypesIndex().url}>Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || (isEdit && !form.isDirty)}
                >
                    {form.processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save changes'
                          : 'Create deduction type'}
                </Button>
            </div>
        </form>
    );
}
