import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import {
    store as accountsStore,
    update as accountsUpdate,
} from '@/routes/admin/chart-of-accounts';
import type {
    AccountOption,
    AccountType,
    CashFlowCategory,
    ChartOfAccountRow,
} from '@/types';

/**
 * Create / edit a chart-of-accounts row in a right-side sheet, so the chart
 * stays visible behind it (RULES.md §807, THEME.md §418). Replaces the
 * standalone create and edit pages, which the router no longer serves.
 */

interface ChartOfAccountEditSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Omitted when creating. */
    account?: ChartOfAccountRow;
    parentOptions: AccountOption[];
}

interface FormShape {
    code: string;
    name: string;
    type: AccountType;
    subtype: string;
    cash_flow_category: CashFlowCategory;
    is_cash_equivalent: boolean;
    parent_id: number | null;
    description: string;
    is_active: boolean;
    [key: string]: string | boolean | number | null;
}

const TYPE_OPTIONS: { value: AccountType; label: string }[] = [
    { value: 'asset', label: 'Asset' },
    { value: 'liability', label: 'Liability' },
    { value: 'equity', label: 'Equity' },
    // Stored as `income`; the interface calls it Revenue everywhere, so the
    // picker and the chart-of-accounts tabs use one word for one thing.
    { value: 'income', label: 'Revenue' },
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

const NO_PARENT = 'none';

/**
 * Normal balance is derived, never chosen. Assets and expenses increase on
 * the debit side; liabilities, equity, and income on the credit side. The
 * server derives the same value from `type` and ignores anything the client
 * sends — this preview only tells the operator what will happen.
 */
function normalBalanceFor(type: AccountType): 'debit' | 'credit' {
    return type === 'asset' || type === 'expense' ? 'debit' : 'credit';
}

function buildDefaults(account?: ChartOfAccountRow): FormShape {
    if (account === undefined) {
        return {
            code: '',
            name: '',
            type: 'expense',
            subtype: '',
            cash_flow_category: 'operating',
            is_cash_equivalent: false,
            parent_id: null,
            description: '',
            is_active: true,
        };
    }

    return {
        code: account.code,
        name: account.name,
        type: account.type,
        subtype: account.subtype ?? '',
        cash_flow_category: account.cash_flow_category,
        is_cash_equivalent: account.is_cash_equivalent,
        parent_id: account.parent_id,
        description: account.description ?? '',
        is_active: account.is_active,
    };
}

export function ChartOfAccountEditSheet({
    open,
    onOpenChange,
    account,
    parentOptions,
}: ChartOfAccountEditSheetProps) {
    const isEdit = account !== undefined;
    const isLocked = account?.is_locked ?? false;
    const form = useForm<FormShape>(buildDefaults(account));

    // Re-seed when the sheet switches between rows (or between create and
    // edit) without unmounting. Keyed on the row so reopening the same row
    // does not clobber in-progress edits.
    const rowKey = account ? `edit:${account.id}` : 'create';

    useEffect(() => {
        const next = buildDefaults(account);
        form.setDefaults(next);
        form.setData(next);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rowKey]);

    // An account may not parent itself. The server rejects it too; this just
    // keeps the picker from offering it.
    const availableParents = parentOptions.filter(
        (option) => option.id !== account?.id,
    );

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        const onSuccess = () => {
            toast.success(
                isEdit
                    ? `Account ${form.data.code} updated.`
                    : `Account ${form.data.code} created.`,
            );
            onOpenChange(false);
        };

        if (isEdit && account) {
            form.patch(accountsUpdate({ chartOfAccount: account.id }).url, {
                preserveScroll: true,
                preserveState: true,
                onSuccess,
            });

            return;
        }

        form.post(accountsStore().url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess,
        });
    };

    const handleCancel = (): void => {
        form.reset();
        form.clearErrors();
        onOpenChange(false);
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col gap-0 p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b">
                    <SheetTitle className="font-serif text-lg">
                        {isEdit
                            ? `Edit ${account.code} — ${account.name}`
                            : 'New account'}
                    </SheetTitle>
                    <SheetDescription>
                        {isLocked
                            ? 'The system posts to this account automatically. Its code and type are fixed because posted entries refer to them.'
                            : 'Accounts are grouped into statement sections by type. The code orders them within a section.'}
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={handleSubmit}
                    className="flex min-h-0 flex-1 flex-col"
                    noValidate
                >
                    <div className="flex-1 space-y-4 overflow-y-auto p-4">
                        <div className="grid gap-4 sm:grid-cols-[9rem_1fr]">
                            <div className="grid gap-2">
                                <Label htmlFor="coa-code">Code</Label>
                                <Input
                                    id="coa-code"
                                    type="text"
                                    maxLength={20}
                                    value={form.data.code}
                                    onChange={(e) =>
                                        form.setData('code', e.target.value)
                                    }
                                    placeholder="1100"
                                    className="font-mono"
                                    disabled={isLocked}
                                    required
                                />
                                <InputError message={form.errors.code} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="coa-name">Name</Label>
                                <Input
                                    id="coa-name"
                                    type="text"
                                    maxLength={160}
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    placeholder="Cash on Hand"
                                    required
                                />
                                <InputError message={form.errors.name} />
                            </div>
                        </div>

                        <Separator />

                        <div className="grid gap-2">
                            <Label htmlFor="coa-type">Type</Label>
                            <Select
                                value={form.data.type}
                                onValueChange={(value) => {
                                    const type = value as AccountType;

                                    // Only an asset can hold cash. Clearing
                                    // the flag here rather than letting the
                                    // server reject it keeps the form from
                                    // holding a combination it cannot save.
                                    form.setData((data) => ({
                                        ...data,
                                        type,
                                        is_cash_equivalent:
                                            type === 'asset'
                                                ? data.is_cash_equivalent
                                                : false,
                                    }));
                                }}
                                disabled={isLocked}
                            >
                                <SelectTrigger id="coa-type" className="w-full">
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
                            <Label htmlFor="coa-subtype">
                                Subtype (optional)
                            </Label>
                            <Input
                                id="coa-subtype"
                                type="text"
                                maxLength={40}
                                value={form.data.subtype}
                                onChange={(e) =>
                                    form.setData('subtype', e.target.value)
                                }
                                placeholder="current_asset"
                                className="font-mono text-xs"
                            />
                            <p className="text-xs text-muted-foreground">
                                Groups accounts within a statement section.
                            </p>
                            <InputError message={form.errors.subtype} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="coa-cash-flow">
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
                                <SelectTrigger
                                    id="coa-cash-flow"
                                    className="w-full"
                                >
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
                            <InputError
                                message={form.errors.cash_flow_category}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="coa-parent">
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
                                        value === NO_PARENT
                                            ? null
                                            : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="coa-parent"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="No parent" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_PARENT}>
                                        No parent
                                    </SelectItem>
                                    {availableParents.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.code} — {option.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.parent_id} />
                        </div>

                        <Separator />

                        <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                            <div className="space-y-0.5">
                                <Label htmlFor="coa-active">Active</Label>
                                <p className="text-xs text-muted-foreground">
                                    Inactive accounts are hidden when picking an
                                    account to post to, but stay readable so
                                    past entries still render.
                                </p>
                            </div>
                            <Switch
                                id="coa-active"
                                checked={form.data.is_active}
                                onCheckedChange={(checked) =>
                                    form.setData('is_active', checked)
                                }
                            />
                        </div>
                        <InputError message={form.errors.is_active} />

                        <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                            <div className="space-y-0.5">
                                <Label htmlFor="coa-cash">Holds cash</Label>
                                <p className="text-xs text-muted-foreground">
                                    {form.data.type === 'asset'
                                        ? 'Money can be received into and paid out of this account, and its balance counts as cash on the Cash Flow Statement. Turn this on for cash and bank accounts only.'
                                        : 'Only an asset account can hold cash. Change the type to asset to turn this on.'}
                                </p>
                            </div>
                            <Switch
                                id="coa-cash"
                                checked={form.data.is_cash_equivalent}
                                disabled={form.data.type !== 'asset'}
                                onCheckedChange={(checked) =>
                                    form.setData('is_cash_equivalent', checked)
                                }
                            />
                        </div>
                        <InputError message={form.errors.is_cash_equivalent} />

                        <div className="grid gap-2">
                            <Label htmlFor="coa-description">
                                Description (optional)
                            </Label>
                            <textarea
                                id="coa-description"
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
                    </div>

                    <SheetFooter className="flex-row justify-end gap-2 border-t bg-background">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleCancel}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || (isEdit && !form.isDirty)
                            }
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Create account'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
