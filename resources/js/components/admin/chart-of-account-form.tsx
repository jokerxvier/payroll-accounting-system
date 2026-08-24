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
    index as accountsIndex,
    store as accountsStore,
    update as accountsUpdate,
} from '@/routes/admin/chart-of-accounts';
import type {
    AccountOption,
    AccountType,
    CashFlowCategory,
    ChartOfAccountRow,
} from '@/types';

type Mode = { kind: 'create' } | { kind: 'edit'; account: ChartOfAccountRow };

interface ChartOfAccountFormProps {
    mode: Mode;
    parentOptions: AccountOption[];
}

interface FormShape {
    code: string;
    name: string;
    type: AccountType;
    subtype: string;
    cash_flow_category: CashFlowCategory;
    parent_id: number | null;
    description: string;
    is_active: boolean;
    [key: string]: string | boolean | number | null;
}

const TYPE_OPTIONS: { value: AccountType; label: string }[] = [
    { value: 'asset', label: 'Asset' },
    { value: 'liability', label: 'Liability' },
    { value: 'equity', label: 'Equity' },
    { value: 'income', label: 'Income' },
    { value: 'expense', label: 'Expense' },
];

const CASH_FLOW_OPTIONS: {
    value: CashFlowCategory;
    label: string;
    hint: string;
}[] = [
    {
        value: 'operating',
        label: 'Operating',
        hint: 'Day-to-day trading — tuition collected, salaries, utilities.',
    },
    {
        value: 'investing',
        label: 'Investing',
        hint: 'Buying or selling long-lived assets.',
    },
    {
        value: 'financing',
        label: 'Financing',
        hint: 'Loans, owner capital, dividends.',
    },
    {
        value: 'none',
        label: 'Not a cash flow',
        hint: 'Non-cash accounts such as depreciation.',
    },
];

/**
 * Normal balance is derived, never chosen. Assets and expenses increase on
 * the debit side; liabilities, equity, and income increase on the credit
 * side. The server derives the same value from `type` and ignores anything
 * the client sends — this preview only tells the operator what will happen.
 */
function normalBalanceFor(type: AccountType): 'debit' | 'credit' {
    return type === 'asset' || type === 'expense' ? 'debit' : 'credit';
}

const NO_PARENT = 'none';

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            code: '',
            name: '',
            type: 'expense',
            subtype: '',
            cash_flow_category: 'operating',
            parent_id: null,
            description: '',
            is_active: true,
        };
    }

    const row = mode.account;

    return {
        code: row.code,
        name: row.name,
        type: row.type,
        subtype: row.subtype ?? '',
        cash_flow_category: row.cash_flow_category,
        parent_id: row.parent_id,
        description: row.description ?? '',
        is_active: row.is_active,
    };
}

export function ChartOfAccountForm({
    mode,
    parentOptions,
}: ChartOfAccountFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));

    const isEdit = mode.kind === 'edit';
    const isLocked = isEdit && mode.account.is_locked;

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (mode.kind === 'create') {
            form.post(accountsStore().url, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Account ${form.data.code} created.`);
                },
            });

            return;
        }

        form.patch(accountsUpdate({ chartOfAccount: mode.account.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Account ${form.data.code} updated.`);
            },
        });
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6" noValidate>
            {isLocked ? (
                <Card className="border-warning/40 bg-warning/5">
                    <CardHeader>
                        <CardTitle className="font-serif text-base">
                            System account
                        </CardTitle>
                        <CardDescription>
                            The system posts to this account automatically. You
                            can rename it, refile its cash-flow category, and
                            edit its description — but the code and type are
                            fixed, because journal entries already posted refer
                            to them.
                        </CardDescription>
                    </CardHeader>
                </Card>
            ) : null}

            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Identity
                    </CardTitle>
                    <CardDescription>
                        The code orders the account within its section and is
                        what appears on every ledger report.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                        <div className="grid gap-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                type="text"
                                maxLength={20}
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                placeholder="e.g. 1100"
                                className="font-mono"
                                disabled={isLocked}
                                required
                            />
                            <InputError message={form.errors.code} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                type="text"
                                maxLength={160}
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Cash on Hand"
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
                        Classification
                    </CardTitle>
                    <CardDescription>
                        Where this account appears in the financial statements,
                        and which side of the ledger it increases on.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="type">Type</Label>
                            <Select
                                value={form.data.type}
                                onValueChange={(value) =>
                                    form.setData('type', value as AccountType)
                                }
                                disabled={isLocked}
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
                                Increases on the{' '}
                                <span className="font-medium text-foreground">
                                    {normalBalanceFor(form.data.type)}
                                </span>{' '}
                                side. Set automatically from the type.
                            </p>
                            <InputError message={form.errors.type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="subtype">Subtype (optional)</Label>
                            <Input
                                id="subtype"
                                type="text"
                                maxLength={40}
                                value={form.data.subtype}
                                onChange={(e) =>
                                    form.setData('subtype', e.target.value)
                                }
                                placeholder="e.g. current_asset"
                                className="font-mono text-xs"
                            />
                            <p className="text-xs text-muted-foreground">
                                Groups accounts within a statement section.
                            </p>
                            <InputError message={form.errors.subtype} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="cash_flow_category">
                            Cash flow category
                        </Label>
                        <Select
                            value={form.data.cash_flow_category}
                            onValueChange={(value) =>
                                form.setData(
                                    'cash_flow_category',
                                    value as CashFlowCategory,
                                )
                            }
                        >
                            <SelectTrigger id="cash_flow_category">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {CASH_FLOW_OPTIONS.map((option) => (
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
                            {
                                CASH_FLOW_OPTIONS.find(
                                    (option) =>
                                        option.value ===
                                        form.data.cash_flow_category,
                                )?.hint
                            }
                        </p>
                        <InputError message={form.errors.cash_flow_category} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="parent_id">
                            Parent account (optional)
                        </Label>
                        <Select
                            value={
                                form.data.parent_id === null
                                    ? NO_PARENT
                                    : String(form.data.parent_id)
                            }
                            onValueChange={(value) =>
                                form.setData(
                                    'parent_id',
                                    value === NO_PARENT ? null : Number(value),
                                )
                            }
                        >
                            <SelectTrigger id="parent_id">
                                <SelectValue placeholder="No parent" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NO_PARENT}>
                                    No parent
                                </SelectItem>
                                {parentOptions.map((option) => (
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
                            Nest this account under a heading, e.g. 1101 Cash on
                            Hand under 1100 Cash.
                        </p>
                        <InputError message={form.errors.parent_id} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Availability
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                        <div className="space-y-0.5">
                            <Label htmlFor="is_active">Active</Label>
                            <p className="text-xs text-muted-foreground">
                                Inactive accounts are hidden when picking an
                                account to post to, but stay readable so past
                                entries still render.
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
                        <Label htmlFor="description">
                            Description (optional)
                        </Label>
                        <textarea
                            id="description"
                            rows={3}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            placeholder="What belongs in this account, and what does not."
                            className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={form.errors.description} />
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center justify-end gap-2">
                <Button asChild variant="outline" type="button">
                    <Link href={accountsIndex().url}>Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || (isEdit && !form.isDirty)}
                >
                    {form.processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save changes'
                          : 'Create account'}
                </Button>
            </div>
        </form>
    );
}
