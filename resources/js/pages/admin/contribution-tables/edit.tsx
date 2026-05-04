import { Head, Link, router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { ArrowLeft, Info, Lightbulb } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { BracketTableForm } from '@/components/admin/contribution-table-forms/bracket-table-form';
import { FlatPercentageForm } from '@/components/admin/contribution-table-forms/flat-percentage-form';
import { PercentageWithCapForm } from '@/components/admin/contribution-table-forms/percentage-with-cap-form';
import { SalaryBandForm } from '@/components/admin/contribution-table-forms/salary-band-form';
import { TieredPercentageForm } from '@/components/admin/contribution-table-forms/tiered-percentage-form';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    index as contributionTablesIndex,
    show as contributionTablesShow,
    update as contributionTablesUpdate,
} from '@/routes/admin/contribution-tables';
import type {
    StatutoryContributionAlgorithm,
    StatutoryContributionAppliesTo,
    StatutoryContributionRow,
} from '@/types';

interface Props {
    contribution: StatutoryContributionRow;
    recommendedCodes: string[];
    algorithmOptions: string[];
}

type FormRules = Record<string, unknown>;

interface FormShape {
    contribution_code: string;
    label: string;
    algorithm: string;
    application_order: string;
    applies_to: StatutoryContributionAppliesTo;
    effective_from: string;
    rules: FormRules;
    notes: string;
}

const ALGORITHM_LABELS: Record<StatutoryContributionAlgorithm, string> = {
    bracket_table: 'Bracket table',
    salary_band: 'Salary band',
    percentage_with_cap: 'Percentage with cap',
    tiered_percentage: 'Tiered percentage',
    flat_percentage: 'Flat percentage (custom tax)',
};

function algorithmLabel(algorithm: string): string {
    return algorithm in ALGORITHM_LABELS
        ? ALGORITHM_LABELS[algorithm as StatutoryContributionAlgorithm]
        : algorithm;
}

function formatEffectiveFrom(iso: string): string {
    try {
        return format(parseISO(iso), 'PP');
    } catch {
        return iso;
    }
}

function buildInitialState(row: StatutoryContributionRow): FormShape {
    return {
        contribution_code: row.contribution_code,
        label: row.label,
        algorithm: row.algorithm,
        application_order:
            typeof row.application_order === 'number'
                ? String(row.application_order)
                : '100',
        applies_to: (row.applies_to ??
            'gross_pay') as StatutoryContributionAppliesTo,
        effective_from: row.effective_from,
        rules: { ...row.rules },
        notes: row.notes ?? '',
    };
}

export default function ContributionTablesEdit({
    contribution,
    algorithmOptions,
}: Props) {
    const initial = buildInitialState(contribution);
    const [data, setData] = useState<FormShape>(initial);
    const [errors, setErrors] = useState<
        Partial<Record<keyof FormShape, string>>
    >({});
    const [processing, setProcessing] = useState(false);
    const [previousAlgorithm, setPreviousAlgorithm] = useState<string>(
        contribution.algorithm,
    );

    const setField = <K extends keyof FormShape>(
        key: K,
        value: FormShape[K],
    ): void => {
        setData((prev) => ({ ...prev, [key]: value }));
    };

    /*
     * Mirror create.tsx: when the admin switches algorithm the rules JSON for
     * the prior strategy can no longer be valid. Reset to {} so the subform
     * starts fresh. Skipping the initial render (algorithm matches the row's
     * algorithm) preserves the prefilled rules on first paint.
     */
    useEffect(() => {
        if (data.algorithm === previousAlgorithm) {
            return;
        }

        // eslint-disable-next-line react-hooks/set-state-in-effect
        setPreviousAlgorithm(data.algorithm);

        setData((prev) => ({ ...prev, rules: {} }));
    }, [data.algorithm, previousAlgorithm]);

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        setProcessing(true);

        router.patch(
            contributionTablesUpdate(contribution.id).url,
            data as unknown as Record<string, never>,
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`${contribution.contribution_code} updated.`);
                    setErrors({});
                },
                onError: (validationErrors) => {
                    setErrors(
                        validationErrors as Partial<
                            Record<keyof FormShape, string>
                        >,
                    );
                },
                onFinish: () => {
                    setProcessing(false);
                },
            },
        );
    };

    const showHref = contributionTablesShow(contribution.id).url;
    const effectiveFromLabel = formatEffectiveFrom(contribution.effective_from);

    return (
        <>
            <Head title={`Edit ${contribution.contribution_code}`} />

            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title={`Edit ${contribution.contribution_code}`}
                    description="In-place edit of a future-dated, open-ended row."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={showHref}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to row
                            </Link>
                        </Button>
                    }
                />

                <div
                    role="status"
                    className="flex items-start gap-3 rounded-md border border-info/40 bg-info/15 p-4 text-sm text-info"
                >
                    <Info
                        aria-hidden="true"
                        className="mt-0.5 h-4 w-4 flex-shrink-0"
                    />
                    <p className="leading-relaxed">
                        This row is editable because no payroll has used it yet
                        (effective_from is in the future). It will become
                        read-only on{' '}
                        <span className="font-semibold tabular-nums">
                            {effectiveFromLabel}
                        </span>
                        . After that, create a new version instead.
                    </p>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6" noValidate>
                    <Card>
                        <CardHeader>
                            <CardTitle className="font-serif text-base">
                                Basic info
                            </CardTitle>
                            <CardDescription>
                                The contribution code is locked. To register a
                                different code, use the Add new version action
                                from the show page.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="contribution_code">
                                        Contribution code
                                    </Label>
                                    <Input
                                        id="contribution_code"
                                        className="font-mono uppercase"
                                        value={data.contribution_code}
                                        disabled
                                        readOnly
                                        aria-describedby="contribution_code-help"
                                    />
                                    <p
                                        id="contribution_code-help"
                                        className="text-xs text-muted-foreground"
                                    >
                                        Contribution code cannot be changed on
                                        edit. Create a new version with the
                                        desired code via the Add new version
                                        button on Show.
                                    </p>
                                    <InputError
                                        message={errors.contribution_code}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="effective_from">
                                        Effective from
                                    </Label>
                                    <DatePicker
                                        id="effective_from"
                                        value={data.effective_from}
                                        onChange={(v) =>
                                            setField('effective_from', v)
                                        }
                                        placeholder="Select start date"
                                        ariaInvalid={Boolean(
                                            errors.effective_from,
                                        )}
                                    />
                                    <InputError
                                        message={errors.effective_from}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="label">Label</Label>
                                <Input
                                    id="label"
                                    type="text"
                                    maxLength={120}
                                    value={data.label}
                                    onChange={(e) =>
                                        setField('label', e.target.value)
                                    }
                                    placeholder="e.g. SSS contribution (2026)"
                                />
                                <InputError message={errors.label} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">Notes</Label>
                                <textarea
                                    id="notes"
                                    rows={3}
                                    value={data.notes}
                                    onChange={(e) =>
                                        setField('notes', e.target.value)
                                    }
                                    placeholder="Source citation, regulation number, etc."
                                    className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                />
                                <InputError message={errors.notes} />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="font-serif text-base">
                                Algorithm
                            </CardTitle>
                            <CardDescription>
                                Determines how the rules below are interpreted
                                when the engine computes payroll.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-2 sm:max-w-sm">
                                <Label htmlFor="algorithm">
                                    Computation algorithm
                                </Label>
                                <Select
                                    value={data.algorithm}
                                    onValueChange={(v) =>
                                        setField('algorithm', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="algorithm"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select an algorithm" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {algorithmOptions.map((algorithm) => (
                                            <SelectItem
                                                key={algorithm}
                                                value={algorithm}
                                            >
                                                {algorithmLabel(algorithm)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.algorithm} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="applies_to">
                                        Applies to
                                    </Label>
                                    <Select
                                        value={data.applies_to}
                                        onValueChange={(v) =>
                                            setField(
                                                'applies_to',
                                                v as StatutoryContributionAppliesTo,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="applies_to"
                                            className="w-full"
                                            aria-describedby="applies_to-help"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="gross_pay">
                                                Gross pay
                                            </SelectItem>
                                            <SelectItem value="taxable_income">
                                                Taxable income
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p
                                        id="applies_to-help"
                                        className="text-xs text-muted-foreground"
                                    >
                                        Most contributions apply to gross pay.
                                        Income tax usually applies to taxable
                                        income (gross minus statutory
                                        deductions).
                                    </p>
                                    <InputError message={errors.applies_to} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="application_order">
                                        Application order
                                    </Label>
                                    <Input
                                        id="application_order"
                                        inputMode="numeric"
                                        pattern="[0-9]*"
                                        value={data.application_order}
                                        onChange={(e) =>
                                            setField(
                                                'application_order',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="100"
                                        className="text-right tabular-nums"
                                        aria-describedby="application_order-help"
                                    />
                                    <p
                                        id="application_order-help"
                                        className="text-xs text-muted-foreground"
                                    >
                                        Lower numbers run first. SSS=10,
                                        PhilHealth=20, Pag-IBIG=30, BIR=90.
                                    </p>
                                    <InputError
                                        message={errors.application_order}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="font-serif text-base">
                                Rules
                            </CardTitle>
                            <CardDescription>
                                Algorithm-specific configuration. Stored as JSON
                                on the row.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {data.algorithm === '' ? (
                                <div className="flex items-start gap-3 rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                    <Lightbulb
                                        aria-hidden="true"
                                        className="mt-0.5 h-4 w-4 flex-shrink-0"
                                    />
                                    <p>
                                        Pick an algorithm above to configure
                                        rules.
                                    </p>
                                </div>
                            ) : (
                                <RulesSubform
                                    algorithm={data.algorithm}
                                    value={data.rules}
                                    onChange={(next) => setField('rules', next)}
                                    errors={errors as Record<string, string>}
                                />
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-2">
                        <Button asChild variant="outline" type="button">
                            <Link href={showHref}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

interface RulesSubformProps {
    algorithm: string;
    value: FormRules;
    onChange: (next: FormRules) => void;
    errors: Record<string, string>;
}

function RulesSubform({
    algorithm,
    value,
    onChange,
    errors,
}: RulesSubformProps) {
    const looseValue = value as Record<string, unknown>;
    const setValue = (next: Record<string, unknown>): void =>
        onChange(next as FormRules);

    if (algorithm === 'bracket_table') {
        return (
            <BracketTableForm
                value={looseValue}
                onChange={setValue}
                errors={errors}
            />
        );
    }

    if (algorithm === 'salary_band') {
        return (
            <SalaryBandForm
                value={looseValue}
                onChange={setValue}
                errors={errors}
            />
        );
    }

    if (algorithm === 'percentage_with_cap') {
        return (
            <PercentageWithCapForm
                value={looseValue}
                onChange={setValue}
                errors={errors}
            />
        );
    }

    if (algorithm === 'tiered_percentage') {
        return (
            <TieredPercentageForm
                value={looseValue}
                onChange={setValue}
                errors={errors}
            />
        );
    }

    if (algorithm === 'flat_percentage') {
        return (
            <FlatPercentageForm
                value={looseValue}
                onChange={setValue}
                errors={errors}
            />
        );
    }

    return (
        <p className="text-sm text-muted-foreground">
            Unknown algorithm: {algorithm}
        </p>
    );
}

ContributionTablesEdit.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        {
            title: 'Contribution tables',
            href: contributionTablesIndex().url,
        },
    ],
};
