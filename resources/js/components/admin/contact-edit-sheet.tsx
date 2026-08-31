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
    store as contactsStore,
    update as contactsUpdate,
} from '@/routes/admin/contacts';
import type { ContactAccountOption, ContactRow } from '@/types';

/**
 * Create / edit a contact in a right-side sheet, so the register stays
 * visible behind it (RULES.md §807). A contact is one record with a handful
 * of fields — the case a sheet suits.
 */

interface ContactEditSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Omitted when creating. */
    contact?: ContactRow;
    receivableAccountOptions?: ContactAccountOption[];
    payableAccountOptions?: ContactAccountOption[];
    /**
     * Which role a new contact starts with. The register creates customers;
     * the invoice form opens this sheet from a bill and wants a supplier.
     * Ignored when editing — an existing contact carries its own flags.
     */
    defaultRole?: 'customer' | 'supplier';
}

interface FormShape {
    code: string;
    name: string;
    is_customer: boolean;
    is_supplier: boolean;
    tin: string;
    email: string;
    phone: string;
    address: string;
    receivable_account_id: number | null;
    payable_account_id: number | null;
    is_active: boolean;
    notes: string;
    [key: string]: string | boolean | number | null;
}

const USE_DEFAULT = 'default';

function buildDefaults(
    contact?: ContactRow,
    defaultRole: 'customer' | 'supplier' = 'customer',
): FormShape {
    if (contact === undefined) {
        return {
            code: '',
            name: '',
            is_customer: defaultRole === 'customer',
            is_supplier: defaultRole === 'supplier',
            tin: '',
            email: '',
            phone: '',
            address: '',
            receivable_account_id: null,
            payable_account_id: null,
            is_active: true,
            notes: '',
        };
    }

    return {
        code: contact.code,
        name: contact.name,
        is_customer: contact.is_customer,
        is_supplier: contact.is_supplier,
        tin: contact.tin ?? '',
        email: contact.email ?? '',
        phone: contact.phone ?? '',
        address: contact.address ?? '',
        receivable_account_id: contact.receivable_account_id,
        payable_account_id: contact.payable_account_id,
        is_active: contact.is_active,
        notes: contact.notes ?? '',
    };
}

export function ContactEditSheet({
    open,
    onOpenChange,
    contact,
    receivableAccountOptions = [],
    payableAccountOptions = [],
    defaultRole = 'customer',
}: ContactEditSheetProps) {
    const isEdit = contact !== undefined;
    const form = useForm<FormShape>(buildDefaults(contact, defaultRole));

    // Re-seed when the sheet switches rows without unmounting. Keyed so
    // reopening the same row does not clobber in-progress edits.
    const rowKey = contact ? `edit:${contact.id}` : `create:${defaultRole}`;

    useEffect(() => {
        const next = buildDefaults(contact, defaultRole);
        form.setDefaults(next);
        form.setData(next);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rowKey]);

    // A contact must be one or the other, or both. Surfaced here so the
    // submit is blocked before the round trip, not only after it.
    const hasRole = form.data.is_customer || form.data.is_supplier;

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        const onSuccess = () => {
            toast.success(
                isEdit
                    ? `Contact '${form.data.name}' updated.`
                    : `Contact '${form.data.name}' created.`,
            );
            onOpenChange(false);
        };

        if (isEdit && contact) {
            form.patch(contactsUpdate({ contact: contact.id }).url, {
                preserveScroll: true,
                preserveState: true,
                onSuccess,
            });

            return;
        }

        form.post(contactsStore().url, {
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
                        {isEdit ? contact.name : 'New contact'}
                    </SheetTitle>
                    <SheetDescription>
                        Who an invoice or bill is addressed to. A contact can be
                        a customer, a supplier, or both.
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
                                <Label htmlFor="contact-code">Code</Label>
                                <Input
                                    id="contact-code"
                                    type="text"
                                    maxLength={32}
                                    value={form.data.code}
                                    onChange={(e) =>
                                        form.setData('code', e.target.value)
                                    }
                                    placeholder="ACME"
                                    className="font-mono"
                                    required
                                />
                                <InputError message={form.errors.code} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="contact-name">Name</Label>
                                <Input
                                    id="contact-name"
                                    type="text"
                                    maxLength={160}
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    placeholder="Acme Trading"
                                    required
                                />
                                <InputError message={form.errors.name} />
                            </div>
                        </div>

                        <Separator />

                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                                <div className="space-y-0.5">
                                    <Label htmlFor="contact-is-customer">
                                        Customer
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        The school invoices this contact.
                                    </p>
                                </div>
                                <Switch
                                    id="contact-is-customer"
                                    checked={form.data.is_customer}
                                    onCheckedChange={(checked) =>
                                        form.setData('is_customer', checked)
                                    }
                                />
                            </div>

                            <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                                <div className="space-y-0.5">
                                    <Label htmlFor="contact-is-supplier">
                                        Supplier
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        This contact bills the school.
                                    </p>
                                </div>
                                <Switch
                                    id="contact-is-supplier"
                                    checked={form.data.is_supplier}
                                    onCheckedChange={(checked) =>
                                        form.setData('is_supplier', checked)
                                    }
                                />
                            </div>

                            {hasRole ? null : (
                                <p className="text-sm text-destructive">
                                    Pick at least one — a contact that is
                                    neither cannot be used on any document.
                                </p>
                            )}
                            <InputError message={form.errors.is_customer} />
                        </div>

                        <Separator />

                        <div className="grid gap-2">
                            <Label htmlFor="contact-tin">TIN (optional)</Label>
                            <Input
                                id="contact-tin"
                                type="text"
                                maxLength={32}
                                value={form.data.tin}
                                onChange={(e) =>
                                    form.setData('tin', e.target.value)
                                }
                                placeholder="123-456-789-000"
                                className="font-mono"
                            />
                            <p className="text-xs text-muted-foreground">
                                Punctuation is stripped on save. Two contacts in
                                this school cannot share a TIN.
                            </p>
                            <InputError message={form.errors.tin} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="contact-email">Email</Label>
                                <Input
                                    id="contact-email"
                                    type="email"
                                    maxLength={160}
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                    placeholder="billing@acme.test"
                                />
                                <InputError message={form.errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="contact-phone">Phone</Label>
                                <Input
                                    id="contact-phone"
                                    type="text"
                                    maxLength={40}
                                    value={form.data.phone}
                                    onChange={(e) =>
                                        form.setData('phone', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.phone} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="contact-address">Address</Label>
                            <textarea
                                id="contact-address"
                                rows={2}
                                value={form.data.address}
                                onChange={(e) =>
                                    form.setData('address', e.target.value)
                                }
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                            />
                            <InputError message={form.errors.address} />
                        </div>

                        <Separator />

                        <AccountOverride
                            id="contact-receivable"
                            label="Receivable account"
                            hint="Leave as the default unless this contact posts through a different receivable account."
                            options={receivableAccountOptions}
                            value={form.data.receivable_account_id}
                            onChange={(value) =>
                                form.setData('receivable_account_id', value)
                            }
                            error={form.errors.receivable_account_id}
                            disabled={!form.data.is_customer}
                        />

                        <AccountOverride
                            id="contact-payable"
                            label="Payable account"
                            hint="Leave as the default unless this contact posts through a different payable account."
                            options={payableAccountOptions}
                            value={form.data.payable_account_id}
                            onChange={(value) =>
                                form.setData('payable_account_id', value)
                            }
                            error={form.errors.payable_account_id}
                            disabled={!form.data.is_supplier}
                        />

                        <Separator />

                        <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                            <div className="space-y-0.5">
                                <Label htmlFor="contact-active">Active</Label>
                                <p className="text-xs text-muted-foreground">
                                    Inactive contacts are hidden when picking
                                    who to invoice, but stay readable on
                                    documents that already name them.
                                </p>
                            </div>
                            <Switch
                                id="contact-active"
                                checked={form.data.is_active}
                                onCheckedChange={(checked) =>
                                    form.setData('is_active', checked)
                                }
                            />
                        </div>
                        <InputError message={form.errors.is_active} />

                        <div className="grid gap-2">
                            <Label htmlFor="contact-notes">Notes</Label>
                            <textarea
                                id="contact-notes"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="Payment terms, who to chase, anything worth knowing before you bill them."
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                            />
                            <InputError message={form.errors.notes} />
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
                                form.processing ||
                                !hasRole ||
                                (isEdit && !form.isDirty)
                            }
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Create contact'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

/**
 * One control-account override.
 *
 * "Use the default" is the first option and the normal answer: null means the
 * school's AR_CONTROL / AP_CONTROL system account, which is what almost every
 * contact should post through.
 */
function AccountOverride({
    id,
    label,
    hint,
    options,
    value,
    onChange,
    error,
    disabled,
}: {
    id: string;
    label: string;
    hint: string;
    options: ContactAccountOption[];
    value: number | null;
    onChange: (value: number | null) => void;
    error?: string;
    disabled?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={value === null ? USE_DEFAULT : String(value)}
                onValueChange={(next) =>
                    onChange(next === USE_DEFAULT ? null : Number(next))
                }
                disabled={disabled}
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={USE_DEFAULT}>
                        Use the school default
                    </SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.id} value={String(option.id)}>
                            {option.code} — {option.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{hint}</p>
            <InputError message={error} />
        </div>
    );
}
