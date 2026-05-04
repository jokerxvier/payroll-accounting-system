import { Head, Link, router } from '@inertiajs/react';
import { format, formatDistanceToNowStrict, parseISO } from 'date-fns';
import { ArrowLeft, CheckCircle2, Copy, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    create as contributionTablesCreate,
    edit as contributionTablesEdit,
    index as contributionTablesIndex,
    show as contributionTablesShow,
    voidMethod as contributionTablesVoid,
} from '@/routes/admin/contribution-tables';
import type {
    BracketTableBracket,
    BracketTableRules,
    FlatPercentageRules,
    PercentageWithCapRules,
    SalaryBand,
    SalaryBandRules,
    StatutoryContributionAlgorithm,
    StatutoryContributionRow,
    TieredPercentageRules,
} from '@/types';

interface Props {
    contribution: StatutoryContributionRow;
    history: StatutoryContributionRow[];
    can: { update: boolean; void: boolean };
}

const ALGORITHM_LABELS: Record<StatutoryContributionAlgorithm, string> = {
    bracket_table: 'Bracket table',
    salary_band: 'Salary band',
    percentage_with_cap: 'Percentage with cap',
    tiered_percentage: 'Tiered percentage',
    flat_percentage: 'Flat percentage (custom tax)',
};

type StatusKey = 'voided' | 'future' | 'active' | 'superseded';

interface StatusDescriptor {
    key: StatusKey;
    label: string;
    variant: 'default' | 'secondary' | 'destructive' | 'outline';
    /** Custom Tailwind class to render the soft-tint pattern from RULES.md §2. */
    className?: string;
}

function describeStatus(row: StatutoryContributionRow): StatusDescriptor {
    if (row.voided_at !== null) {
        return { key: 'voided', label: 'Voided', variant: 'destructive' };
    }

    const today = startOfTodayIso();
    const effectiveFromInFuture = row.effective_from > today;

    if (effectiveFromInFuture && row.effective_to === null) {
        return {
            key: 'future',
            label: 'Future',
            variant: 'outline',
            className: 'border-info/40 bg-info/15 text-info',
        };
    }

    if (!effectiveFromInFuture && row.effective_to === null) {
        return {
            key: 'active',
            label: 'Active',
            variant: 'outline',
            className: 'border-success/40 bg-success/15 text-success',
        };
    }

    return {
        key: 'superseded',
        label: 'Superseded',
        variant: 'secondary',
    };
}

function startOfTodayIso(): string {
    return format(new Date(), 'yyyy-MM-dd');
}

function formatAbsoluteDate(iso: string | null): string {
    if (iso === null) {
        return 'open-ended';
    }

    try {
        return format(parseISO(iso), 'PP');
    } catch {
        return iso;
    }
}

function formatRelativeDate(iso: string | null): string | null {
    if (iso === null) {
        return null;
    }

    try {
        const parsed = parseISO(iso);
        const today = new Date();
        const distance = formatDistanceToNowStrict(parsed, { addSuffix: true });

        if (parsed.getTime() === today.getTime()) {
            return 'today';
        }

        return distance;
    } catch {
        return null;
    }
}

function formatDateTime(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    try {
        return format(parseISO(iso), 'PPp');
    } catch {
        return iso;
    }
}

function basisPointsToPercent(bp: number): string {
    return (bp / 100).toFixed(2);
}

function centavosToPesos(centavos: number): number {
    return centavos / 100;
}

function algorithmLabel(algorithm: string): string {
    if (algorithm in ALGORITHM_LABELS) {
        return ALGORITHM_LABELS[algorithm as StatutoryContributionAlgorithm];
    }

    return algorithm;
}

export default function ContributionTablesShow({
    contribution,
    history,
    can,
}: Props) {
    const [voidOpen, setVoidOpen] = useState(false);
    const [voiding, setVoiding] = useState(false);
    const status = describeStatus(contribution);

    const effectiveFromAbsolute = formatAbsoluteDate(
        contribution.effective_from,
    );
    const effectiveFromRelative = formatRelativeDate(
        contribution.effective_from,
    );
    const effectiveToAbsolute = formatAbsoluteDate(contribution.effective_to);
    const effectiveToRelative = formatRelativeDate(contribution.effective_to);

    const handleVoidConfirm = (): void => {
        setVoiding(true);
        router.post(
            contributionTablesVoid(contribution.id).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setVoiding(false);
                    setVoidOpen(false);
                },
            },
        );
    };

    const cloneHref = contributionTablesCreate({
        query: { clone: String(contribution.id) },
    }).url;
    const editHref = contributionTablesEdit(contribution.id).url;
    const backHref = contributionTablesIndex().url;

    return (
        <>
            <Head title={`${contribution.contribution_code} — Contribution`} />

            <div className="mx-auto max-w-5xl space-y-6 p-4">
                <PageHeader
                    eyebrow={contribution.contribution_code}
                    title={contribution.label}
                    description={algorithmLabel(contribution.algorithm)}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="ghost">
                                <Link href={backHref}>
                                    <ArrowLeft className="mr-1 h-4 w-4" />
                                    Back
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={cloneHref}>
                                    <Copy className="mr-1 h-4 w-4" />
                                    Add new version
                                </Link>
                            </Button>
                            {can.update && (
                                <Button asChild variant="outline">
                                    <Link href={editHref}>
                                        <Pencil className="mr-1 h-4 w-4" />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can.void && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    onClick={() => setVoidOpen(true)}
                                >
                                    <Trash2 className="mr-1 h-4 w-4" />
                                    Void
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Badge
                        variant={status.variant}
                        className={status.className}
                        aria-label={`Status: ${status.label}`}
                    >
                        {status.key === 'active' && (
                            <CheckCircle2
                                aria-hidden="true"
                                className="h-3 w-3"
                            />
                        )}
                        {status.label}
                    </Badge>
                    <span className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                        ID #{contribution.id}
                    </span>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="font-serif text-base">
                            Effective dates
                        </CardTitle>
                        <CardDescription>
                            A row is active for{' '}
                            <code>effective_from ≤ d &lt; effective_to</code>.
                            Open-ended rows have no end date.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-6 sm:grid-cols-2">
                            <div>
                                <dt className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                                    Effective from
                                </dt>
                                <dd className="mt-1 font-serif text-lg font-semibold tabular-nums">
                                    {effectiveFromAbsolute}
                                </dd>
                                {effectiveFromRelative && (
                                    <dd className="text-xs text-muted-foreground">
                                        {effectiveFromRelative}
                                    </dd>
                                )}
                            </div>
                            <div>
                                <dt className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                                    Effective to
                                </dt>
                                <dd className="mt-1 font-serif text-lg font-semibold tabular-nums">
                                    {effectiveToAbsolute}
                                </dd>
                                {effectiveToRelative && (
                                    <dd className="text-xs text-muted-foreground">
                                        {effectiveToRelative}
                                    </dd>
                                )}
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="font-serif text-base">
                            Algorithm rules
                        </CardTitle>
                        <CardDescription>
                            How the engine reads this row at compute time.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <RulesSummary
                            algorithm={contribution.algorithm}
                            rules={contribution.rules}
                        />
                    </CardContent>
                </Card>

                <HistoryCard history={history} />

                <Card>
                    <CardHeader>
                        <CardTitle className="font-serif text-base">
                            Audit
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-muted-foreground">
                        <p>
                            <span className="font-medium text-foreground">
                                Created:
                            </span>{' '}
                            <span className="tabular-nums">
                                {formatDateTime(contribution.created_at)}
                            </span>
                        </p>
                        <p>
                            <span className="font-medium text-foreground">
                                Updated:
                            </span>{' '}
                            <span className="tabular-nums">
                                {formatDateTime(contribution.updated_at)}
                            </span>
                        </p>
                        {contribution.voided_at !== null && (
                            <>
                                <Separator className="my-2" />
                                <p className="text-destructive">
                                    <span className="font-medium">Voided:</span>{' '}
                                    <span className="tabular-nums">
                                        {formatDateTime(contribution.voided_at)}
                                    </span>
                                    {contribution.voided_by && (
                                        <>
                                            {' '}
                                            by{' '}
                                            <span className="font-medium">
                                                {contribution.voided_by.name}
                                            </span>
                                        </>
                                    )}
                                </p>
                            </>
                        )}
                        {contribution.notes && (
                            <>
                                <Separator className="my-2" />
                                <div>
                                    <p className="font-medium text-foreground">
                                        Notes
                                    </p>
                                    <p className="mt-1 max-w-prose leading-relaxed text-foreground">
                                        {contribution.notes}
                                    </p>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>

            <AlertDialog open={voidOpen} onOpenChange={setVoidOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Void {contribution.contribution_code}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Voiding removes this contribution from all future
                            payroll computation. It will be hidden from the
                            registry and the engine on every effective-date
                            lookup. This cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={voiding}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            disabled={voiding}
                            onClick={(event) => {
                                event.preventDefault();
                                handleVoidConfirm();
                            }}
                        >
                            {voiding ? 'Voiding…' : 'Void contribution'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

interface HistoryCardProps {
    history: StatutoryContributionRow[];
}

function HistoryCard({ history }: HistoryCardProps) {
    if (history.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="font-serif text-base">
                    Other versions
                </CardTitle>
                <CardDescription>
                    Every other row for this contribution code, most recent
                    first. Voided versions are included.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="divide-y rounded-md border">
                    {history.map((row) => {
                        const status = describeStatus(row);

                        return (
                            <li key={row.id}>
                                <Link
                                    href={contributionTablesShow(row.id).url}
                                    className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-accent/40"
                                    aria-label={`Open version ${row.id}`}
                                >
                                    <div className="space-y-1">
                                        <p className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                                            {row.contribution_code} · #{row.id}
                                        </p>
                                        <p className="text-sm font-medium tabular-nums">
                                            {formatAbsoluteDate(
                                                row.effective_from,
                                            )}{' '}
                                            <span className="text-muted-foreground">
                                                →
                                            </span>{' '}
                                            {formatAbsoluteDate(
                                                row.effective_to,
                                            )}
                                        </p>
                                    </div>
                                    <Badge
                                        variant={status.variant}
                                        className={status.className}
                                    >
                                        {status.label}
                                    </Badge>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
    );
}

interface RulesSummaryProps {
    algorithm: string;
    rules: Record<string, unknown>;
}

function RulesSummary({ algorithm, rules }: RulesSummaryProps) {
    if (algorithm === 'bracket_table') {
        return <BracketTableSummary rules={rules} />;
    }

    if (algorithm === 'salary_band') {
        return <SalaryBandSummary rules={rules} />;
    }

    if (algorithm === 'tiered_percentage') {
        return <TieredPercentageSummary rules={rules} />;
    }

    if (algorithm === 'percentage_with_cap') {
        return <PercentageWithCapSummary rules={rules} />;
    }

    if (algorithm === 'flat_percentage') {
        return <FlatPercentageSummary rules={rules} />;
    }

    return (
        <p className="text-sm text-muted-foreground">
            Unknown algorithm: {algorithm}
        </p>
    );
}

function castBracketTable(value: Record<string, unknown>): BracketTableRules {
    const periodTypes =
        typeof value.period_types === 'object' && value.period_types !== null
            ? (value.period_types as Record<string, BracketTableBracket[]>)
            : {};

    return { period_types: periodTypes };
}

function BracketTableSummary({ rules }: { rules: Record<string, unknown> }) {
    const cast = castBracketTable(rules);
    const entries = Object.entries(cast.period_types);

    if (entries.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No brackets configured.
            </p>
        );
    }

    return (
        <div className="space-y-6">
            {entries.map(([periodKey, brackets]) => (
                <section key={periodKey} className="space-y-2">
                    <h3 className="font-serif text-base font-semibold capitalize">
                        {periodKey.replace(/_/g, ' ')}
                    </h3>
                    <div className="overflow-x-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Lower
                                    </TableHead>
                                    <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Upper
                                    </TableHead>
                                    <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Base tax
                                    </TableHead>
                                    <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Excess rate
                                    </TableHead>
                                    <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Excess over
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {brackets.map((bracket, index) => (
                                    <TableRow
                                        key={`${periodKey}-${index.toString()}`}
                                    >
                                        <TableCell className="text-right tabular-nums">
                                            <Money
                                                amount={centavosToPesos(
                                                    bracket.lower,
                                                )}
                                                showSymbol={false}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {bracket.upper === null ? (
                                                <span className="text-muted-foreground">
                                                    Top tier
                                                </span>
                                            ) : (
                                                <Money
                                                    amount={centavosToPesos(
                                                        bracket.upper,
                                                    )}
                                                    showSymbol={false}
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <Money
                                                amount={centavosToPesos(
                                                    bracket.base_tax,
                                                )}
                                                showSymbol={false}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {basisPointsToPercent(
                                                bracket.excess_rate_bp,
                                            )}
                                            %
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <Money
                                                amount={centavosToPesos(
                                                    bracket.excess_over,
                                                )}
                                                showSymbol={false}
                                            />
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

function castSalaryBand(value: Record<string, unknown>): SalaryBandRules {
    return {
        bands: Array.isArray(value.bands) ? (value.bands as SalaryBand[]) : [],
    };
}

function SalaryBandSummary({ rules }: { rules: Record<string, unknown> }) {
    const cast = castSalaryBand(rules);

    if (cast.bands.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No bands configured.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            <p className="text-xs text-muted-foreground">
                {cast.bands.length} band
                {cast.bands.length === 1 ? '' : 's'} configured.
            </p>
            <div className="overflow-x-auto rounded-md border">
                <Table className="text-xs">
                    <TableHeader>
                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                Lower
                            </TableHead>
                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                Upper
                            </TableHead>
                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                MSC
                            </TableHead>
                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                EE
                            </TableHead>
                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                ER
                            </TableHead>
                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                ER aux
                            </TableHead>
                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                EE aux
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {cast.bands.map((band, index) => (
                            <TableRow key={`band-${index.toString()}`}>
                                <TableCell className="text-right tabular-nums">
                                    <Money
                                        amount={centavosToPesos(band.lower)}
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {band.upper === null ? (
                                        <span className="text-muted-foreground">
                                            Top tier
                                        </span>
                                    ) : (
                                        <Money
                                            amount={centavosToPesos(band.upper)}
                                            showSymbol={false}
                                        />
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    <Money
                                        amount={centavosToPesos(
                                            band.contribution_base,
                                        )}
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    <Money
                                        amount={centavosToPesos(
                                            band.employee_share,
                                        )}
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    <Money
                                        amount={centavosToPesos(
                                            band.employer_share,
                                        )}
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    <Money
                                        amount={centavosToPesos(
                                            band.employer_aux_share,
                                        )}
                                        showSymbol={false}
                                    />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    <Money
                                        amount={centavosToPesos(
                                            band.employee_aux_share,
                                        )}
                                        showSymbol={false}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}

function castTieredPercentage(
    value: Record<string, unknown>,
): TieredPercentageRules {
    const lower =
        typeof value.lower === 'object' && value.lower !== null
            ? (value.lower as { ee_bp?: unknown; er_bp?: unknown })
            : {};
    const upper =
        typeof value.upper === 'object' && value.upper !== null
            ? (value.upper as { ee_bp?: unknown; er_bp?: unknown })
            : {};

    return {
        threshold: typeof value.threshold === 'number' ? value.threshold : 0,
        max_msc: typeof value.max_msc === 'number' ? value.max_msc : 0,
        lower: {
            ee_bp: typeof lower.ee_bp === 'number' ? lower.ee_bp : 0,
            er_bp: typeof lower.er_bp === 'number' ? lower.er_bp : 0,
        },
        upper: {
            ee_bp: typeof upper.ee_bp === 'number' ? upper.ee_bp : 0,
            er_bp: typeof upper.er_bp === 'number' ? upper.er_bp : 0,
        },
    };
}

function TieredPercentageSummary({
    rules,
}: {
    rules: Record<string, unknown>;
}) {
    const cast = castTieredPercentage(rules);

    return (
        <dl className="grid gap-4 sm:grid-cols-2">
            <SummaryItem label="Threshold">
                <Money amount={centavosToPesos(cast.threshold)} />
            </SummaryItem>
            <SummaryItem label="Maximum MSC">
                <Money amount={centavosToPesos(cast.max_msc)} />
            </SummaryItem>
            <SummaryItem label="Lower tier · employee">
                {basisPointsToPercent(cast.lower.ee_bp)}%
            </SummaryItem>
            <SummaryItem label="Lower tier · employer">
                {basisPointsToPercent(cast.lower.er_bp)}%
            </SummaryItem>
            <SummaryItem label="Upper tier · employee">
                {basisPointsToPercent(cast.upper.ee_bp)}%
            </SummaryItem>
            <SummaryItem label="Upper tier · employer">
                {basisPointsToPercent(cast.upper.er_bp)}%
            </SummaryItem>
        </dl>
    );
}

function castPercentageWithCap(
    value: Record<string, unknown>,
): PercentageWithCapRules {
    const split =
        typeof value.split === 'object' && value.split !== null
            ? (value.split as { employee_bp?: unknown; employer_bp?: unknown })
            : {};

    return {
        rate_bp: typeof value.rate_bp === 'number' ? value.rate_bp : 0,
        floor: typeof value.floor === 'number' ? value.floor : 0,
        ceiling: typeof value.ceiling === 'number' ? value.ceiling : 0,
        split: {
            employee_bp:
                typeof split.employee_bp === 'number' ? split.employee_bp : 0,
            employer_bp:
                typeof split.employer_bp === 'number' ? split.employer_bp : 0,
        },
    };
}

function PercentageWithCapSummary({
    rules,
}: {
    rules: Record<string, unknown>;
}) {
    const cast = castPercentageWithCap(rules);

    return (
        <dl className="grid gap-4 sm:grid-cols-2">
            <SummaryItem label="Premium rate">
                {basisPointsToPercent(cast.rate_bp)}%
            </SummaryItem>
            <SummaryItem label="Floor">
                <Money amount={centavosToPesos(cast.floor)} />
            </SummaryItem>
            <SummaryItem label="Ceiling">
                <Money amount={centavosToPesos(cast.ceiling)} />
            </SummaryItem>
            <SummaryItem label="Employee share">
                {basisPointsToPercent(cast.split.employee_bp)}%
            </SummaryItem>
            <SummaryItem label="Employer share">
                {basisPointsToPercent(cast.split.employer_bp)}%
            </SummaryItem>
        </dl>
    );
}

function castFlatPercentage(
    value: Record<string, unknown>,
): FlatPercentageRules {
    return {
        rate_bp: typeof value.rate_bp === 'number' ? value.rate_bp : 0,
        employee_share_bp:
            typeof value.employee_share_bp === 'number'
                ? value.employee_share_bp
                : 0,
        employer_share_bp:
            typeof value.employer_share_bp === 'number'
                ? value.employer_share_bp
                : 0,
        cap: typeof value.cap === 'number' ? value.cap : null,
    };
}

function FlatPercentageSummary({ rules }: { rules: Record<string, unknown> }) {
    const cast = castFlatPercentage(rules);

    return (
        <dl className="grid gap-4 sm:grid-cols-2">
            <SummaryItem label="Rate">
                {basisPointsToPercent(cast.rate_bp)}%
            </SummaryItem>
            <SummaryItem label="Employee share">
                {basisPointsToPercent(cast.employee_share_bp)}%
            </SummaryItem>
            <SummaryItem label="Employer share">
                {basisPointsToPercent(cast.employer_share_bp)}%
            </SummaryItem>
            <SummaryItem label="Cap">
                {cast.cap === null ? (
                    <span className="text-muted-foreground">No cap</span>
                ) : (
                    <Money amount={centavosToPesos(cast.cap)} />
                )}
            </SummaryItem>
        </dl>
    );
}

function SummaryItem({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-1 text-sm font-medium tabular-nums">
                {children}
            </dd>
        </div>
    );
}

ContributionTablesShow.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        {
            title: 'Contribution tables',
            href: contributionTablesIndex().url,
        },
    ],
};
