import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
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
    index as taxRatesIndex,
    store as taxRatesStore,
    update as taxRatesUpdate,
} from '@/routes/admin/tax-rates';
import type { AccountOption, TaxRateRow, TaxRateType } from '@/types';

type Mode = { kind: 'create' } | { kind: 'edit'; taxRate: TaxRateRow };

interface TaxRateFormProps {
    mode: Mode;
    accountOptions: AccountOption[];
}

interface FormShape {
    code: string;
    name: string;
    rate_bps: number;
    type: TaxRateType;
    account_id: number | null;
    is_active: boolean;
    [key: string]: string | boolean | number | null;
}

const TYPE_OPTIONS: {
    value: TaxRateType;
    label: string;
    hint: string;
    postsTax: boolean;
}[] = [
    {
        value: 'vat_sales',
        label: 'Output VAT (sales)',
        hint: 'VAT you collect from customers and owe to the BIR.',
        postsTax: true,
    },
    {
        value: 'vat_purchase',
        label: 'Input VAT (purchases)',
        hint: 'VAT you pay to suppliers and credit against output VAT.',
        postsTax: true,
    },
    {
        value: 'exempt',
        label: 'VAT exempt',
        hint: 'No VAT charged. Reported as its own subtotal on the invoice.',
        postsTax: false,
    },
    {
        value: 'zero_rated',
        label: 'Zero-rated',
        hint: 'No VAT charged, reported separately from exempt sales.',
        postsTax: false,
    },
];

const NO_ACCOUNT = 'none';

/** Basis points → editable percent string. 1200 → "12". */
function bpsToPercent(bps: number): string {
    return String(bps / 100);
}

/** Percent string → basis points. "12.5" → 1250. Blank → 0. */
function percentToBps(input: string): number {
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

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            code: '',
            name: '',
            rate_bps: 1200,
            type: 'vat_sales',
            account_id: null,
            is_active: true,
        };
    }

    const row = mode.taxRate;

    return {
        code: row.code,
        name: row.name,
        rate_bps: row.rate_bps,
        type: row.type,
        account_id: row.account_id,
        is_active: row.is_active,
    };
}

export function TaxRateForm({ mode, accountOptions }: TaxRateFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));

    // Local string state so the operator can type "12." without the
    // controlled input snapping back to the basis-points integer.
    const [percentStr, setPercentStr] = useState(() =>
        bpsToPercent(form.data.rate_bps),
    );

    const selectedType = TYPE_OPTIONS.find(
        (option) => option.value === form.data.type,
    );
    const isZeroByDefinition =
        form.data.type === 'exempt' || form.data.type === 'zero_rated';
    const requiresAccount =
        form.data.rate_bps > 0 && selectedType?.postsTax === true;

    const isEdit = mode.kind === 'edit';

    const handleTypeChange = (value: string): void => {
        const nextType = value as TaxRateType;
        form.setData('type', nextType);

        // Exempt and zero-rated are 0% by definition, and post to no
        // account. Clearing both here keeps the form honest instead of
        // letting the operator submit a combination the server rejects.
        if (nextType === 'exempt' || nextType === 'zero_rated') {
            setPercentStr('0');
            form.setData('rate_bps', 0);
            form.setData('account_id', null);
        }
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (mode.kind === 'create') {
            form.post(taxRatesStore().url, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Tax rate '${form.data.code}' created.`);
                },
            });

            return;
        }

        form.patch(taxRatesUpdate({ taxRate: mode.taxRate.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Tax rate '${form.data.code}' updated.`);
            },
        });
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6" noValidate>
            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Identity
                    </CardTitle>
                    <CardDescription>
                        The name appears in the tax picker on every invoice and
                        bill line.
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
                                placeholder="e.g. VAT_12_SALES"
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
                                placeholder="e.g. VAT 12% (Sales)"
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
                        Rate and treatment
                    </CardTitle>
                    <CardDescription>
                        How much tax this rate charges, and where it posts.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="type">Treatment</Label>
                        <Select
                            value={form.data.type}
                            onValueChange={handleTypeChange}
                        >
                            <SelectTrigger id="type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {TYPE_OPTIONS.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            {selectedType?.hint}
                        </p>
                        <InputError message={form.errors.type} />
                    </div>

                    <div className="grid gap-2 sm:max-w-[12rem]">
                        <Label htmlFor="rate_bps">Rate</Label>
                        <div className="relative">
                            <Input
                                id="rate_bps"
                                inputMode="decimal"
                                pattern="[0-9]*\.?[0-9]*"
                                value={percentStr}
                                disabled={isZeroByDefinition}
                                onChange={(e) => {
                                    const next = e.target.value;
                                    setPercentStr(next);
                                    form.setData(
                                        'rate_bps',
                                        percentToBps(next),
                                    );
                                }}
                                onBlur={() => {
                                    setPercentStr(
                                        bpsToPercent(form.data.rate_bps),
                                    );
                                }}
                                className="pr-7 text-right tabular-nums"
                                required
                            />
                            <span
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                            >
                                %
                            </span>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {isZeroByDefinition
                                ? 'Fixed at 0% for this treatment.'
                                : `Stored as ${form.data.rate_bps} basis points.`}
                        </p>
                        <InputError message={form.errors.rate_bps} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="account_id">
                            Posts to account
                            {requiresAccount ? '' : ' (optional)'}
                        </Label>
                        <Select
                            value={
                                form.data.account_id === null
                                    ? NO_ACCOUNT
                                    : String(form.data.account_id)
                            }
                            onValueChange={(value) =>
                                form.setData(
                                    'account_id',
                                    value === NO_ACCOUNT ? null : Number(value),
                                )
                            }
                            disabled={isZeroByDefinition}
                        >
                            <SelectTrigger id="account_id">
                                <SelectValue placeholder="No account" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NO_ACCOUNT}>
                                    No account
                                </SelectItem>
                                {accountOptions.map((option) => (
                                    <SelectItem
                                        key={option.id}
                                        value={String(option.id)}
                                    >
                                        {option.code} — {option.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            {isZeroByDefinition
                                ? 'Exempt and zero-rated lines charge no tax, so nothing is posted.'
                                : 'Required for a VAT rate — without it an invoice using this rate cannot balance.'}
                        </p>
                        <InputError message={form.errors.account_id} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Availability
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                        <div className="space-y-0.5">
                            <Label htmlFor="is_active">Active</Label>
                            <p className="text-xs text-muted-foreground">
                                Inactive rates are hidden from new invoice lines
                                but stay readable on documents that already use
                                them.
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
                </CardContent>
            </Card>

            <div className="flex items-center justify-end gap-2">
                <Button asChild variant="outline" type="button">
                    <Link href={taxRatesIndex().url}>Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || (isEdit && !form.isDirty)}
                >
                    {form.processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save changes'
                          : 'Create tax rate'}
                </Button>
            </div>
        </form>
    );
}
