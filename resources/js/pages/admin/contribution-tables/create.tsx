import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Lightbulb } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { BracketTableForm } from '@/components/admin/contribution-table-forms/bracket-table-form';
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
    create as contributionTablesCreate,
    index as contributionTablesIndex,
    store as contributionTablesStore,
} from '@/routes/admin/contribution-tables';
import type {
    StatutoryContributionAlgorithm,
    StatutoryContributionCode,
} from '@/types';

interface Props {
    codeOptions: string[];
    algorithmOptions: string[];
}

/**
 * The `rules` payload is a JSON-serializable object whose exact shape depends
 * on the chosen algorithm. Subforms narrow it internally before reading.
 *
 * Form state is held locally (not via Inertia's useForm) because Inertia v3's
 * recursive `FormDataType` generic constraint can't terminate over a
 * polymorphic `Record<string, unknown>` JSON payload. We submit via
 * `router.post` and carry server-side validation errors back through the
 * `onError` callback into local state — same shape `useForm.errors` would
 * have.
 */
type FormRules = Record<string, unknown>;

interface FormShape {
    contribution_code: string;
    label: string;
    algorithm: string;
    effective_from: string;
    rules: FormRules;
    notes: string;
}

const CODE_LABELS: Record<StatutoryContributionCode, string> = {
    BIR_WITHHOLDING: 'BIR withholding tax',
    SSS: 'SSS',
    PHILHEALTH: 'PhilHealth',
    PAGIBIG: 'Pag-IBIG',
};

const ALGORITHM_LABELS: Record<StatutoryContributionAlgorithm, string> = {
    bracket_table: 'Bracket table',
    salary_band: 'Salary band',
    percentage_with_cap: 'Percentage with cap',
    tiered_percentage: 'Tiered percentage',
};

function readPrefilledCode(): string {
    if (typeof window === 'undefined') {
        return '';
    }

    const params = new URLSearchParams(window.location.search);

    return params.get('code') ?? '';
}

function codeLabel(code: string): string {
    return code in CODE_LABELS
        ? CODE_LABELS[code as StatutoryContributionCode]
        : code;
}

function algorithmLabel(algorithm: string): string {
    return algorithm in ALGORITHM_LABELS
        ? ALGORITHM_LABELS[algorithm as StatutoryContributionAlgorithm]
        : algorithm;
}

export default function ContributionTablesCreate({
    codeOptions,
    algorithmOptions,
}: Props) {
    const initialCode = readPrefilledCode();
    const isValidInitialCode = codeOptions.includes(initialCode);

    const [data, setData] = useState<FormShape>({
        contribution_code: isValidInitialCode ? initialCode : '',
        label: '',
        algorithm: '',
        effective_from: '',
        rules: {},
        notes: '',
    });
    const [errors, setErrors] = useState<
        Partial<Record<keyof FormShape, string>>
    >({});
    const [processing, setProcessing] = useState(false);

    const setField = <K extends keyof FormShape>(
        key: K,
        value: FormShape[K],
    ): void => {
        setData((prev) => ({ ...prev, [key]: value }));
    };

    // Reset the rules payload whenever the algorithm changes — different
    // strategies expect entirely different shapes, and carrying values across
    // would always trip the strategy's validateRules() server-side.
    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setData((prev) => ({ ...prev, rules: {} }));
    }, [data.algorithm]);

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        setProcessing(true);

        // Cast through `unknown` because Inertia's `RequestPayload` constraint
        // recursively typechecks every leaf — our polymorphic `rules` JSON is
        // serialized fine at runtime but defies that recursion.
        router.post(
            contributionTablesStore().url,
            data as unknown as Record<string, never>,
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`${data.contribution_code} version added.`);
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

    return (
        <>
            <Head title="Add Contribution Version" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title="Add a new version"
                    description="Pick the contribution code, algorithm, and rules. The new version will supersede the currently-active one for this code."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={contributionTablesIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                <form onSubmit={handleSubmit} className="space-y-6" noValidate>
                    <Card>
                        <CardHeader>
                            <CardTitle className="font-serif text-base">
                                Basic info
                            </CardTitle>
                            <CardDescription>
                                Identify which statutory code this version
                                applies to and when it takes effect.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="contribution_code">
                                        Contribution code
                                    </Label>
                                    <Select
                                        value={data.contribution_code}
                                        onValueChange={(v) =>
                                            setField('contribution_code', v)
                                        }
                                    >
                                        <SelectTrigger
                                            id="contribution_code"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Select a code" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {codeOptions.map((code) => (
                                                <SelectItem
                                                    key={code}
                                                    value={code}
                                                >
                                                    {codeLabel(code)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
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
                        <CardContent>
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
                            <Link href={contributionTablesIndex().url}>
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save version'}
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
    // Subforms accept `Record<string, unknown>` so they don't depend on
    // Inertia's FormDataConvertible. The Inertia useForm holds the value as
    // FormDataConvertible (so it serializes cleanly), and we cast at this
    // single boundary; both shapes are runtime-equivalent JSON objects.
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

    return (
        <p className="text-sm text-muted-foreground">
            Unknown algorithm: {algorithm}
        </p>
    );
}

ContributionTablesCreate.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        {
            title: 'Contribution tables',
            href: contributionTablesIndex().url,
        },
        {
            title: 'Add new version',
            href: contributionTablesCreate().url,
        },
    ],
};
