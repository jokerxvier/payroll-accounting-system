import { Head, useForm } from '@inertiajs/react';
import { ReportExportMenu } from '@/components/admin/report-export-menu';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { generalLedger as generalLedgerRoute } from '@/routes/admin/reports';
import type { AccountLedger, LedgerAccountOption } from '@/types/ledger-report';

interface Props {
    filters: { from: string; to: string; account_id: number | null };
    accountOptions: LedgerAccountOption[];
    ledger: AccountLedger | null;
}

function Amount({ centavos }: { centavos: number }) {
    if (centavos === 0) {
        return <span className="text-muted-foreground">&mdash;</span>;
    }

    return <Money amount={centavos / 100} showSymbol={false} />;
}

export default function GeneralLedgerReport({
    filters,
    accountOptions,
    ledger,
}: Props) {
    const form = useForm({
        from: filters.from,
        to: filters.to,
        account_id: filters.account_id ? String(filters.account_id) : '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.get(generalLedgerRoute().url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const exportUrl = `${generalLedgerRoute().url}/export?from=${form.data.from}&to=${form.data.to}&account_id=${form.data.account_id}`;

    return (
        <>
            <Head title="General ledger" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="FINANCIAL REPORTS"
                    title="General ledger"
                    description="One account's posted movement in date order, with the balance brought forward and a running balance on every line."
                    actions={
                        <ReportExportMenu
                            baseUrl={exportUrl}
                            disabled={ledger === null}
                        />
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-medium">
                            Filter
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="flex flex-wrap items-end gap-3"
                        >
                            <div className="space-y-1">
                                <Label htmlFor="account_id">Account</Label>
                                <Select
                                    value={form.data.account_id}
                                    onValueChange={(value) =>
                                        form.setData('account_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="account_id"
                                        className="w-[22rem]"
                                    >
                                        <SelectValue placeholder="Choose an account…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accountOptions.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {account.code} {account.name}
                                                {!account.is_active &&
                                                    ' (inactive)'}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="from">From</Label>
                                <Input
                                    id="from"
                                    type="date"
                                    value={form.data.from}
                                    onChange={(event) =>
                                        form.setData('from', event.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="to">To</Label>
                                <Input
                                    id="to"
                                    type="date"
                                    value={form.data.to}
                                    onChange={(event) =>
                                        form.setData('to', event.target.value)
                                    }
                                />
                            </div>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={form.processing}
                            >
                                Apply
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {ledger === null ? (
                    <Card>
                        <CardContent className="py-12 text-center text-sm text-muted-foreground">
                            Choose an account to read its ledger.
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <span className="font-mono text-xs">
                                    {ledger.account.code}
                                </span>
                                {ledger.account.name}
                                <Badge
                                    variant="secondary"
                                    className="capitalize"
                                >
                                    {ledger.account.type}
                                </Badge>
                            </CardTitle>
                            <p className="text-xs text-muted-foreground">
                                Closing{' '}
                                <Money
                                    amount={
                                        Math.abs(
                                            ledger.closing_natural_centavos,
                                        ) / 100
                                    }
                                />{' '}
                                {ledger.closing_natural_centavos < 0
                                    ? 'contra'
                                    : ledger.account.normal_balance}
                            </p>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto rounded-md border">
                                <Table className="text-sm">
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Date
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Entry
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Description
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Contra accounts
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Debit
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Credit
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Balance
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow className="bg-muted/20">
                                            <TableCell>
                                                {filters.from}
                                            </TableCell>
                                            <TableCell
                                                colSpan={3}
                                                className="text-muted-foreground italic"
                                            >
                                                Balance brought forward
                                            </TableCell>
                                            <TableCell />
                                            <TableCell />
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        ledger.opening_raw_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                        </TableRow>
                                        {ledger.lines.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={7}
                                                    className="py-8 text-center text-sm text-muted-foreground"
                                                >
                                                    No posted movement on this
                                                    account inside the range.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            ledger.lines.map((line) => (
                                                <TableRow key={line.line_id}>
                                                    <TableCell>
                                                        {line.date}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs">
                                                        {line.entry_number}
                                                        {line.is_reversal && (
                                                            <Badge
                                                                variant="outline"
                                                                className="ml-2"
                                                            >
                                                                reversal
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {line.description ??
                                                            line.narration}
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">
                                                        {line.contra_accounts.join(
                                                            '; ',
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Amount
                                                            centavos={
                                                                line.debit_centavos
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Amount
                                                            centavos={
                                                                line.credit_centavos
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        <Money
                                                            amount={
                                                                line.running_raw_centavos /
                                                                100
                                                            }
                                                            showSymbol={false}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="font-medium"
                                            >
                                                Balance carried forward
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        ledger.total_debit_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        ledger.total_credit_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        ledger.closing_raw_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    </TableFooter>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

GeneralLedgerReport.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/chart-of-accounts' },
        { title: 'General ledger', href: '/admin/reports/general-ledger' },
    ],
};
