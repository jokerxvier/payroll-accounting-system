import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronRight,
    FileText,
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import {
    create as contributionTablesCreate,
    edit as contributionTablesEdit,
    index as contributionTablesIndex,
    show as contributionTablesShow,
    voidMethod as contributionTablesVoid,
} from '@/routes/admin/contribution-tables';
import type {
    StatutoryContributionAlgorithm,
    StatutoryContributionGrouped,
    StatutoryContributionRow,
} from '@/types';

interface Props {
    grouped: StatutoryContributionGrouped;
    recommendedCodes: string[];
    algorithmOptions: string[];
    can: { modify: boolean };
}

const CODE_LABELS: Record<string, string> = {
    BIR_WITHHOLDING: 'BIR withholding tax',
    SSS: 'SSS',
    PHILHEALTH: 'PhilHealth',
    PAGIBIG: 'Pag-IBIG',
    CITY_TAX: 'City / local tax',
};

const ALGORITHM_LABELS: Record<StatutoryContributionAlgorithm, string> = {
    bracket_table: 'Bracket table',
    salary_band: 'Salary band',
    percentage_with_cap: 'Percentage with cap',
    tiered_percentage: 'Tiered percentage',
    flat_percentage: 'Flat percentage (custom tax)',
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
    return CODE_LABELS[code] ?? latestRow?.label ?? code;
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
    recommendedCodes,
    can,
}: Props) {
    // Show recommended codes plus any custom codes that already have rows.
    // De-dupe while preserving order: recommended first (defines display order),
    // then custom codes from grouped that aren't already in the list.
    const codes: string[] = Array.from(
        new Set([...recommendedCodes, ...Object.keys(grouped)]),
    );

    // Track which row's void confirmation is open. Only one dialog is mounted
    // at a time even though multiple editable rows can render their own Void
    // trigger — keying by row id keeps the AlertDialog state model trivial.
    const [voidTargetId, setVoidTargetId] = useState<number | null>(null);
    const [voiding, setVoiding] = useState(false);

    const voidTarget: StatutoryContributionRow | null = (() => {
        if (voidTargetId === null) {
            return null;
        }

        for (const code of codes) {
            const found = (grouped[code] ?? []).find(
                (r) => r.id === voidTargetId,
            );

            if (found) {
                return found;
            }
        }

        return null;
    })();

    const handleVoidConfirm = (): void => {
        if (voidTargetId === null) {
            return;
        }

        setVoiding(true);
        router.post(
            contributionTablesVoid(voidTargetId).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setVoiding(false);
                    setVoidTargetId(null);
                },
            },
        );
    };

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
                                                        <TableHead className="w-[140px] text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                            <span className="sr-only">
                                                                Actions
                                                            </span>
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {rows.map((row) => {
                                                        const isVoided =
                                                            row.voided_at !==
                                                            null;
                                                        const showInlineActions =
                                                            row.is_editable &&
                                                            can.modify;

                                                        return (
                                                            <TableRow
                                                                key={row.id}
                                                                className={cn(
                                                                    'transition-colors hover:bg-muted/30',
                                                                    isVoided &&
                                                                        'opacity-60',
                                                                )}
                                                            >
                                                                <TableCell className="tabular-nums">
                                                                    <Link
                                                                        href={
                                                                            contributionTablesShow(
                                                                                row.id,
                                                                            )
                                                                                .url
                                                                        }
                                                                        className="inline-flex items-center gap-2 hover:underline"
                                                                        aria-label={`Open contribution row ${row.id}`}
                                                                    >
                                                                        {formatDate(
                                                                            row.effective_from,
                                                                        )}
                                                                        {isVoided && (
                                                                            <Badge variant="destructive">
                                                                                Voided
                                                                            </Badge>
                                                                        )}
                                                                    </Link>
                                                                </TableCell>
                                                                <TableCell>
                                                                    {row.effective_to ===
                                                                    null ? (
                                                                        isVoided ? (
                                                                            <span className="text-muted-foreground">
                                                                                —
                                                                            </span>
                                                                        ) : (
                                                                            <Badge className="bg-success/15 text-success hover:bg-success/15">
                                                                                Active
                                                                            </Badge>
                                                                        )
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
                                                                <TableCell className="text-right">
                                                                    <div className="flex items-center justify-end gap-1">
                                                                        {showInlineActions && (
                                                                            <>
                                                                                <Button
                                                                                    asChild
                                                                                    size="icon"
                                                                                    variant="ghost"
                                                                                    className="h-7 w-7"
                                                                                >
                                                                                    <Link
                                                                                        href={
                                                                                            contributionTablesEdit(
                                                                                                row.id,
                                                                                            )
                                                                                                .url
                                                                                        }
                                                                                        aria-label={`Edit row ${row.id}`}
                                                                                    >
                                                                                        <Pencil className="h-3.5 w-3.5" />
                                                                                    </Link>
                                                                                </Button>
                                                                                <Button
                                                                                    type="button"
                                                                                    size="icon"
                                                                                    variant="ghost"
                                                                                    className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                                                    aria-label={`Void row ${row.id}`}
                                                                                    onClick={() =>
                                                                                        setVoidTargetId(
                                                                                            row.id,
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    <Trash2 className="h-3.5 w-3.5" />
                                                                                </Button>
                                                                            </>
                                                                        )}
                                                                        <Link
                                                                            href={
                                                                                contributionTablesShow(
                                                                                    row.id,
                                                                                )
                                                                                    .url
                                                                            }
                                                                            aria-label={`View row ${row.id}`}
                                                                            className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                                                        >
                                                                            <ChevronRight className="h-4 w-4" />
                                                                        </Link>
                                                                    </div>
                                                                </TableCell>
                                                            </TableRow>
                                                        );
                                                    })}
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

            <AlertDialog
                open={voidTargetId !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setVoidTargetId(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Void{' '}
                            {voidTarget
                                ? voidTarget.contribution_code
                                : 'contribution'}
                            ?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Voiding removes this contribution from all future
                            payroll computation. This cannot be undone.
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

ContributionTablesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        {
            title: 'Contribution tables',
            href: contributionTablesIndex().url,
        },
    ],
};
