import { Link } from '@inertiajs/react';
import {
    StatementAmount,
    StatementVariance,
    StatementVariancePercent,
} from '@/components/admin/statement-amount';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { StatementLine } from '@/types/financial-statement';

/**
 * Past this many amount columns the account column is pinned, so a figure in
 * the ninth month can still be read against its account. Below it the whole
 * table fits and pinning would only add a seam.
 */
const PIN_ACCOUNT_COLUMN_FROM = 4;

// A pinned cell has to be opaque, and its row's tint is not: `bg-muted/40`
// over the card. These are that same tint already mixed, so the pinned cell
// matches the rest of its row instead of standing out as a darker block.
const PINNED_HEADING = 'bg-[color-mix(in_oklab,var(--muted)_40%,var(--card))]';
const PINNED_TOTAL = 'bg-[color-mix(in_oklab,var(--muted)_20%,var(--card))]';

const HEAD = 'text-xs tracking-wide text-muted-foreground uppercase';

const ROW: Record<StatementLine['kind'], string> = {
    heading: 'bg-muted/40 hover:bg-muted/40',
    account: '',
    computed: '',
    detail: 'border-0 text-xs text-muted-foreground hover:bg-transparent',
    subtotal: 'font-medium hover:bg-transparent',
    total: 'border-y-2 border-foreground/20 bg-muted/20 font-semibold hover:bg-muted/20',
};

const INDENT: Record<StatementLine['kind'], string> = {
    heading: '',
    account: 'pl-6',
    computed: 'pl-6',
    detail: 'pl-12',
    subtotal: 'pl-6',
    total: '',
};

/**
 * The body of a financial statement: one row per line, in the order sent.
 *
 * Shared by the Balance Sheet and the Income Statement. It knows how each
 * kind of line is set and nothing about what a statement contains — the
 * sections, their order and their subtotals are all decided by the server,
 * which sends the same list to the spreadsheet and the PDF.
 */
export function StatementTable({
    columnLabels,
    lines,
    hasVariance = false,
    hrefFor,
}: {
    columnLabels: string[];
    lines: StatementLine[];
    hasVariance?: boolean;
    /** Where a figure can be read in full, or null when it cannot. */
    hrefFor: (line: StatementLine, columnIndex: number) => string | null;
}) {
    const pinned = columnLabels.length >= PIN_ACCOUNT_COLUMN_FROM;
    const span = columnLabels.length + (hasVariance ? 2 : 0) + 1;

    // An opaque background on the pinned cell, or the figures scroll
    // through the account names rather than behind them.
    const pin = (background: string) =>
        pinned ? cn('sticky left-0 z-10 print:static', background) : '';

    return (
        <div className="rounded-md border print:border-0">
            <Table className="text-sm">
                <TableHeader>
                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                        <TableHead
                            className={cn(
                                HEAD,
                                'min-w-[16rem]',
                                pin(PINNED_HEADING),
                            )}
                        >
                            Account
                        </TableHead>
                        {columnLabels.map((label, index) => (
                            <TableHead
                                // By position: a custom comparison can be
                                // given the same dates as the range itself.
                                key={index}
                                className={cn(
                                    HEAD,
                                    'min-w-[7.5rem] text-right whitespace-nowrap',
                                )}
                            >
                                {label}
                            </TableHead>
                        ))}
                        {hasVariance ? (
                            <>
                                <TableHead
                                    className={cn(
                                        HEAD,
                                        'min-w-[7.5rem] text-right',
                                    )}
                                >
                                    Variance
                                </TableHead>
                                <TableHead
                                    className={cn(
                                        HEAD,
                                        'min-w-[5rem] text-right',
                                    )}
                                >
                                    %
                                </TableHead>
                            </>
                        ) : null}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {lines.map((line) =>
                        line.kind === 'heading' ? (
                            <TableRow key={line.key} className={ROW.heading}>
                                <TableCell
                                    colSpan={pinned ? 1 : span}
                                    className={cn(
                                        'text-xs font-medium tracking-wide text-muted-foreground uppercase',
                                        pin(PINNED_HEADING),
                                    )}
                                >
                                    {line.name}
                                </TableCell>
                                {pinned ? (
                                    <TableCell colSpan={span - 1} />
                                ) : null}
                            </TableRow>
                        ) : (
                            <TableRow key={line.key} className={ROW[line.kind]}>
                                <TableCell
                                    // A row header: it names the row the
                                    // figures belong to.
                                    role="rowheader"
                                    className={cn(
                                        INDENT[line.kind],
                                        pin(
                                            line.kind === 'total'
                                                ? PINNED_TOTAL
                                                : 'bg-card',
                                        ),
                                    )}
                                >
                                    <LineName
                                        line={line}
                                        href={
                                            columnLabels.length === 1
                                                ? hrefFor(line, 0)
                                                : null
                                        }
                                    />
                                </TableCell>
                                {line.amounts_centavos.map((amount, index) => (
                                    <TableCell
                                        key={index}
                                        className="text-right"
                                    >
                                        <Figure
                                            line={line}
                                            amount={amount}
                                            // The name carries the link when
                                            // there is one column; with
                                            // several, each figure links to
                                            // its own dates.
                                            href={
                                                columnLabels.length > 1
                                                    ? hrefFor(line, index)
                                                    : null
                                            }
                                            label={columnLabels[index]}
                                        />
                                    </TableCell>
                                ))}
                                {hasVariance ? (
                                    <>
                                        <TableCell className="text-right">
                                            <StatementVariance
                                                centavos={
                                                    line.variance_centavos
                                                }
                                                favourable={
                                                    line.variance_favourable
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <StatementVariancePercent
                                                basisPoints={line.variance_bp}
                                                favourable={
                                                    line.variance_favourable
                                                }
                                            />
                                        </TableCell>
                                    </>
                                ) : null}
                            </TableRow>
                        ),
                    )}
                </TableBody>
            </Table>
        </div>
    );
}

function LineName({
    line,
    href,
}: {
    line: StatementLine;
    href: string | null;
}) {
    const name = (
        <>
            {line.code ? (
                <span className="mr-2 font-mono text-xs text-muted-foreground">
                    {line.code}
                </span>
            ) : null}
            <span className={line.kind === 'computed' ? 'italic' : undefined}>
                {line.name}
            </span>
        </>
    );

    return (
        <>
            {href ? (
                <Link
                    href={href}
                    className="underline-offset-4 hover:underline"
                >
                    {name}
                </Link>
            ) : (
                name
            )}
            {!line.is_active ? (
                <span className="ml-2 text-xs text-muted-foreground">
                    Inactive
                </span>
            ) : null}
            {line.is_unclassified ? (
                <span className="ml-2 text-xs text-muted-foreground">
                    No subtype
                </span>
            ) : null}
        </>
    );
}

function Figure({
    line,
    amount,
    href,
    label,
}: {
    line: StatementLine;
    amount: number;
    href: string | null;
    label: string;
}) {
    const figure = (
        <StatementAmount
            centavos={amount}
            blankWhenZero={line.kind === 'account'}
        />
    );

    if (href === null || amount === 0) {
        return figure;
    }

    return (
        <Link
            href={href}
            aria-label={`${line.name}, ${label}: open ledger`}
            className="underline-offset-4 hover:underline"
        >
            {figure}
        </Link>
    );
}
