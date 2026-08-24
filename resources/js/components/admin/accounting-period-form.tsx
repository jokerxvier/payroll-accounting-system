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
    index as periodsIndex,
    store as periodsStore,
    update as periodsUpdate,
} from '@/routes/admin/accounting-periods';
import type { AccountingPeriodEditable } from '@/types';

type Mode =
    | { kind: 'create' }
    | { kind: 'edit'; period: AccountingPeriodEditable };

interface AccountingPeriodFormProps {
    mode: Mode;
}

interface FormShape {
    code: string;
    name: string;
    start_date: string;
    end_date: string;
    fiscal_year: number | null;
    [key: string]: string | number | null;
}

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            code: '',
            name: '',
            start_date: '',
            end_date: '',
            fiscal_year: null,
        };
    }

    const row = mode.period;

    return {
        code: row.code,
        name: row.name ?? '',
        start_date: row.start_date,
        end_date: row.end_date,
        fiscal_year: row.fiscal_year,
    };
}

export function AccountingPeriodForm({ mode }: AccountingPeriodFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));

    const isEdit = mode.kind === 'edit';

    /**
     * Picking a start date fills in the code, name, and fiscal year for the
     * calendar month it falls in — which is what almost every period is.
     * Each field stays editable for schools on a non-calendar fiscal year.
     * Only blank fields are filled, so this never overwrites typing.
     */
    const handleStartDateChange = (value: string): void => {
        form.setData('start_date', value);

        if (value === '') {
            return;
        }

        const start = new Date(`${value}T00:00:00`);

        if (Number.isNaN(start.getTime())) {
            return;
        }

        const year = start.getFullYear();
        const month = String(start.getMonth() + 1).padStart(2, '0');

        if (form.data.code === '') {
            form.setData('code', `${year}-${month}`);
        }

        if (form.data.name === '') {
            form.setData(
                'name',
                start.toLocaleDateString(undefined, {
                    month: 'long',
                    year: 'numeric',
                }),
            );
        }

        if (form.data.fiscal_year === null) {
            form.setData('fiscal_year', year);
        }

        if (form.data.end_date === '') {
            // Last day of the same month.
            const end = new Date(year, start.getMonth() + 1, 0);
            const endMonth = String(end.getMonth() + 1).padStart(2, '0');
            const endDay = String(end.getDate()).padStart(2, '0');
            form.setData(
                'end_date',
                `${end.getFullYear()}-${endMonth}-${endDay}`,
            );
        }
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (mode.kind === 'create') {
            form.post(periodsStore().url, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Period ${form.data.code} created.`);
                },
            });

            return;
        }

        form.patch(periodsUpdate({ accountingPeriod: mode.period.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Period ${form.data.code} updated.`);
            },
        });
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6" noValidate>
            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Period
                    </CardTitle>
                    <CardDescription>
                        Periods may not overlap — every entry has to belong to
                        exactly one.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="start_date">Start date</Label>
                            <Input
                                id="start_date"
                                type="date"
                                value={form.data.start_date}
                                onChange={(e) =>
                                    handleStartDateChange(e.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.start_date} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="end_date">End date</Label>
                            <Input
                                id="end_date"
                                type="date"
                                value={form.data.end_date}
                                onChange={(e) =>
                                    form.setData('end_date', e.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.end_date} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-[10rem_1fr_10rem]">
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
                                placeholder="2026-08"
                                className="font-mono"
                                required
                            />
                            <InputError message={form.errors.code} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name (optional)</Label>
                            <Input
                                id="name"
                                type="text"
                                maxLength={120}
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="August 2026"
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="fiscal_year">Fiscal year</Label>
                            <Input
                                id="fiscal_year"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                value={
                                    form.data.fiscal_year === null
                                        ? ''
                                        : String(form.data.fiscal_year)
                                }
                                onChange={(e) => {
                                    const next = e.target.value.trim();
                                    form.setData(
                                        'fiscal_year',
                                        next === '' ? null : Number(next),
                                    );
                                }}
                                placeholder="2026"
                                className="text-right tabular-nums"
                                required
                            />
                            <InputError message={form.errors.fiscal_year} />
                        </div>
                    </div>

                    <p className="text-xs text-muted-foreground">
                        Fiscal year groups periods for the year-end close and
                        the Statement of Changes in Equity. It defaults to the
                        year the period starts in.
                    </p>
                </CardContent>
            </Card>

            <div className="flex items-center justify-end gap-2">
                <Button asChild variant="outline" type="button">
                    <Link href={periodsIndex().url}>Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || (isEdit && !form.isDirty)}
                >
                    {form.processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save changes'
                          : 'Create period'}
                </Button>
            </div>
        </form>
    );
}
