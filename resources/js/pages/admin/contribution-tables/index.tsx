import { Head, Link } from '@inertiajs/react';
import { FileText, Plus, ShieldCheck } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    index as contributionTablesIndex,
} from '@/routes/admin/contribution-tables';
import type {
    StatutoryContributionAlgorithm,
    StatutoryContributionCode,
    StatutoryContributionGrouped,
    StatutoryContributionRow,
} from '@/types';

interface Props {
    grouped: StatutoryContributionGrouped;
    codeOptions: string[];
    algorithmOptions: string[];
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

const DATE_FORMATTER = new Intl.DateTimeFormat('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});

function formatDate(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    const parsed = new Date(iso);

    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return DATE_FORMATTER.format(parsed);
}

function codeLabel(code: string, latestRow?: StatutoryContributionRow): string {
    if (code in CODE_LABELS) {
        return CODE_LABELS[code as StatutoryContributionCode];
    }

    return latestRow?.label ?? code;
}

function algorithmLabel(algorithm: string): string {
    if (algorithm in ALGORITHM_LABELS) {
        return ALGORITHM_LABELS[algorithm as StatutoryContributionAlgorithm];
    }

    return algorithm;
}

function truncate(text: string | null, max = 80): string {
    if (text === null || text === '') {
        return '—';
    }

    if (text.length <= max) {
        return text;
    }

    return `${text.slice(0, max - 1)}…`;
}

export default function ContributionTablesIndex({
    grouped,
    codeOptions,
}: Props) {
    const codes: string[] =
        codeOptions.length > 0 ? codeOptions : Object.keys(grouped);

    return (
        <>
            <Head title="Contribution Tables" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title="Contribution tables"
                    description="Versioned, effective-dated rates for statutory contributions. Adding a new version supersedes the current one."
                    actions={
                        <Button asChild>
                            <Link href={contributionTablesCreate().url}>
                                <Plus className="mr-1 h-4 w-4" />
                                Add new version
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-2">
                    {codes.map((code) => {
                        const rows = grouped[code] ?? [];
                        const latestRow = rows[0];

                        return (
                            <Card key={code}>
                                <CardHeader className="flex flex-row items-start justify-between gap-4">
                                    <div className="space-y-1">
                                        <p className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                                            {code}
                                        </p>
                                        <CardTitle className="font-serif text-lg">
                                            {codeLabel(code, latestRow)}
                                        </CardTitle>
                                        {latestRow && (
                                            <p className="text-xs text-muted-foreground">
                                                {algorithmLabel(
                                                    latestRow.algorithm,
                                                )}
                                            </p>
                                        )}
                                    </div>
                                    <Button asChild size="sm" variant="outline">
                                        <Link
                                            href={
                                                contributionTablesCreate({
                                                    query: { code },
                                                }).url
                                            }
                                        >
                                            <Plus className="mr-1 h-3.5 w-3.5" />
                                            Add version
                                        </Link>
                                    </Button>
                                </CardHeader>
                                <CardContent>
                                    {rows.length === 0 ? (
                                        <EmptyState
                                            icon={FileText}
                                            title="No versions yet"
                                            description="Add the first effective-dated rate set to start computing payroll."
                                            action={
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={
                                                            contributionTablesCreate(
                                                                {
                                                                    query: {
                                                                        code,
                                                                    },
                                                                },
                                                            ).url
                                                        }
                                                    >
                                                        Create first version
                                                    </Link>
                                                </Button>
                                            }
                                        />
                                    ) : (
                                        <div className="overflow-x-auto rounded-md border">
                                            <Table className="text-sm">
                                                <TableHeader>
                                                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                            Effective from
                                                        </TableHead>
                                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                            Effective to
                                                        </TableHead>
                                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                            Algorithm
                                                        </TableHead>
                                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                            Notes
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {rows.map((row) => (
                                                        <TableRow key={row.id}>
                                                            <TableCell className="tabular-nums">
                                                                {formatDate(
                                                                    row.effective_from,
                                                                )}
                                                            </TableCell>
                                                            <TableCell>
                                                                {row.effective_to ===
                                                                null ? (
                                                                    <Badge className="bg-success/15 text-success hover:bg-success/15">
                                                                        Active
                                                                    </Badge>
                                                                ) : (
                                                                    <span className="text-muted-foreground tabular-nums">
                                                                        {formatDate(
                                                                            row.effective_to,
                                                                        )}
                                                                    </span>
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="text-muted-foreground">
                                                                {algorithmLabel(
                                                                    row.algorithm,
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="text-xs text-muted-foreground">
                                                                {truncate(
                                                                    row.notes,
                                                                )}
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {codes.length === 0 && (
                    <EmptyState
                        icon={ShieldCheck}
                        title="No contribution codes registered"
                        description="The system has no statutory contribution codes available yet."
                    />
                )}
            </div>
        </>
    );
}

ContributionTablesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        {
            title: 'Contribution tables',
            href: contributionTablesIndex().url,
        },
    ],
};
