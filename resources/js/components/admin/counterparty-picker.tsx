import { Check, ChevronsUpDown, Plus } from 'lucide-react';
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
import type { ContactPickerOption } from '@/types';

/**
 * Choose the contact a document is addressed to.
 *
 * A combobox rather than a Select because the register is long: a school with
 * eight hundred families has eight hundred customers, and a plain listbox
 * makes finding one of them a scrolling exercise. cmdk filters on each item's
 * `value` string, so name and TIN both go in it — an operator holding a BIR
 * document has the TIN, not the spelling.
 *
 * Shared by the invoice, payment and recurring-schedule forms. `noun` is what
 * this counterparty is called on the calling document — "customer" on a sales
 * invoice or a receipt, "supplier" on a bill — and it carries into the
 * placeholder, the empty state and the New button, so none of those has to be
 * passed separately.
 *
 * Disabled rather than empty when there is nothing to choose: an empty picker
 * and a picker with nothing matching look identical, and only one of them has
 * an obvious next action.
 */
export function CounterpartyPicker({
    id,
    noun,
    options,
    value,
    disabled,
    onSelect,
    onAddNew,
}: {
    id: string;
    noun: string;
    options: ContactPickerOption[];
    value: number | null;
    disabled: boolean;
    onSelect: (contactId: number) => void;
    /** Omitted when the operator may not create a contact. */
    onAddNew?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.id === value) ?? null;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    className="w-full justify-between font-normal"
                >
                    <span
                        className={cn(
                            'truncate',
                            !selected && 'text-muted-foreground',
                        )}
                    >
                        {selected
                            ? `${selected.name}${selected.tin ? ` · TIN ${selected.tin}` : ''}`
                            : options.length === 0
                              ? `No ${noun}s yet`
                              : `Choose a ${noun}`}
                    </span>
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-(--radix-popover-trigger-width) p-0"
                align="start"
            >
                <Command>
                    <CommandInput placeholder={`Search by name or TIN…`} />
                    <CommandList>
                        <CommandEmpty>No {noun} found.</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => (
                                <CommandItem
                                    key={option.id}
                                    // What cmdk matches the search against.
                                    // The id is on the end to keep the value
                                    // unique — two families share a surname
                                    // often enough, and duplicate values break
                                    // keyboard navigation.
                                    value={`${option.name} ${option.tin ?? ''} ${option.id}`}
                                    onSelect={() => {
                                        setOpen(false);
                                        onSelect(option.id);
                                    }}
                                >
                                    <Check
                                        className={cn(
                                            'mr-2 h-4 w-4 shrink-0',
                                            value === option.id
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    <span className="truncate">
                                        {option.name}
                                    </span>
                                    {option.tin && (
                                        <span className="ml-auto shrink-0 font-mono text-xs text-muted-foreground">
                                            {option.tin}
                                        </span>
                                    )}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                    {/*
                      Outside the CommandList on purpose: an item inside it is
                      filtered like any other, and the moment the search finds
                      nothing is exactly when "create this one" is the action
                      wanted.
                    */}
                    {onAddNew && (
                        <div className="border-t p-1">
                            <Button
                                type="button"
                                variant="ghost"
                                className="w-full justify-start font-normal"
                                onClick={() => {
                                    setOpen(false);
                                    onAddNew();
                                }}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                New {noun}
                            </Button>
                        </div>
                    )}
                </Command>
            </PopoverContent>
        </Popover>
    );
}
