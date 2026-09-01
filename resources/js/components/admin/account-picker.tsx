import { Check, ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { LedgerAccountOption } from '@/types/ledger-report';

/**
 * Choose an account from the chart.
 *
 * A combobox rather than a Select for the reason `CounterpartyPicker` is one:
 * a seeded chart is forty accounts before a school adds any of its own, and
 * finding `5210 Utilities Expense` in a flat listbox is a scrolling exercise.
 * An accountant reaching for it holds one of two things — the code, or the
 * name — so cmdk matches on both, plus the type, which is how somebody who
 * knows they want "an expense account" starts looking.
 *
 * Grouped by type, in balance-sheet-then-income-statement order. The chart is
 * ordered by code everywhere else and that ordering already sorts the types
 * apart, so the groups add headings rather than rearranging anything.
 *
 * Note `income` is labelled **Revenue**: that is the word the interface uses
 * throughout, matching the chart-of-accounts screen.
 */

const TYPE_LABELS: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    income: 'Revenue',
    expense: 'Expenses',
};

/** Balance sheet first, then the income statement — how a chart is read. */
const TYPE_ORDER = ['asset', 'liability', 'equity', 'income', 'expense'];

export function AccountPicker({
    id,
    options,
    value,
    onSelect,
    placeholder = 'Choose an account…',
    disabled = false,
}: {
    id: string;
    options: LedgerAccountOption[];
    /** The selected account id, or null. */
    value: number | null;
    onSelect: (accountId: number) => void;
    placeholder?: string;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.id === value) ?? null;

    const groups = TYPE_ORDER.map((type) => ({
        type,
        label: TYPE_LABELS[type] ?? type,
        accounts: options.filter((option) => option.type === type),
    })).filter((group) => group.accounts.length > 0);

    // Anything with a type the chart does not use — hand-made rows do turn up
    // — still has to be reachable, or an account would be invisible in the
    // one place built for finding accounts.
    const ungrouped = options.filter(
        (option) => !TYPE_ORDER.includes(option.type),
    );

    if (ungrouped.length > 0) {
        groups.push({ type: 'other', label: 'Other', accounts: ungrouped });
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled || options.length === 0}
                    className="w-full justify-between font-normal"
                >
                    <span
                        className={cn(
                            'truncate',
                            !selected && 'text-muted-foreground',
                        )}
                    >
                        {selected ? (
                            <>
                                <span className="font-mono">
                                    {selected.code}
                                </span>{' '}
                                {selected.name}
                                {!selected.is_active && ' (inactive)'}
                            </>
                        ) : options.length === 0 ? (
                            'No accounts yet'
                        ) : (
                            placeholder
                        )}
                    </span>
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-(--radix-popover-trigger-width) p-0"
                align="start"
            >
                <Command>
                    <CommandInput placeholder="Search by code or name…" />
                    <CommandList>
                        <CommandEmpty>No account found.</CommandEmpty>
                        {groups.map((group) => (
                            <CommandGroup
                                key={group.type}
                                heading={group.label}
                            >
                                {group.accounts.map((account) => (
                                    <CommandItem
                                        key={account.id}
                                        /*
                                         * What cmdk matches against. The id is
                                         * on the end to keep the value unique
                                         * — a chart can carry two accounts
                                         * with the same name, and duplicate
                                         * values break keyboard navigation.
                                         */
                                        value={`${account.code} ${account.name} ${group.label} ${account.id}`}
                                        onSelect={() => {
                                            setOpen(false);
                                            onSelect(account.id);
                                        }}
                                    >
                                        <Check
                                            className={cn(
                                                'mr-2 h-4 w-4 shrink-0',
                                                value === account.id
                                                    ? 'opacity-100'
                                                    : 'opacity-0',
                                            )}
                                        />
                                        <span className="w-14 shrink-0 font-mono text-xs text-muted-foreground">
                                            {account.code}
                                        </span>
                                        <span className="truncate">
                                            {account.name}
                                        </span>
                                        {!account.is_active && (
                                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                                inactive
                                            </span>
                                        )}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
