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
    store as seriesStore,
    update as seriesUpdate,
} from '@/routes/admin/document-series';
import type { DocumentSeriesRow, DocumentSeriesType } from '@/types';

/**
 * Create / edit a numbering series in a right-side sheet, so the list stays
 * visible behind it (RULES.md §807). A series is one record with a handful of
 * fields — the case a sheet suits.
 *
 * The Authority To Print fields are optional and stay that way. A school that
 * has not registered this document type still needs a working counter, and
 * the printed document simply omits the permit footer rather than showing
 * blanks.
 */

const TYPE_LABELS: Record<DocumentSeriesType, string> = {
    sales_invoice: 'Sales invoice',
    official_receipt: 'Official receipt',
    credit_note: 'Credit note',
    bill: 'Bill (internal reference)',
};

interface DocumentSeriesEditSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Omitted when creating. */
    series?: DocumentSeriesRow;
    documentTypes: DocumentSeriesType[];
}

interface FormShape {
    document_type: DocumentSeriesType;
    label: string;
    prefix: string;
    padding: number;
    next_number: number;
    serial_start: number | null;
    serial_end: number | null;
    atp_number: string;
    permit_issued_at: string;
    is_active: boolean;
    [key: string]: string | number | boolean | null;
}

function buildDefaults(series?: DocumentSeriesRow): FormShape {
    if (series === undefined) {
        return {
            document_type: 'sales_invoice',
            label: '',
            prefix: '',
            padding: 6,
            next_number: 1,
            serial_start: null,
            serial_end: null,
            atp_number: '',
            permit_issued_at: '',
            is_active: true,
        };
    }

    return {
        document_type: series.document_type,
        label: series.label,
        prefix: series.prefix ?? '',
        padding: series.padding,
        next_number: series.next_number,
        serial_start: series.serial_start,
        serial_end: series.serial_end,
        atp_number: series.atp_number ?? '',
        permit_issued_at: series.permit_issued_at ?? '',
        is_active: series.is_active,
    };
}

/** Parse an optional whole number, keeping an emptied field as null. */
function optionalNumber(value: string): number | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : Number(trimmed);
}

export function DocumentSeriesEditSheet({
    open,
    onOpenChange,
    series,
    documentTypes,
}: DocumentSeriesEditSheetProps) {
    const isEdit = series !== undefined;
    const form = useForm<FormShape>(buildDefaults(series));

    // Re-seed when the sheet switches rows without unmounting. Keyed so
    // reopening the same row does not clobber in-progress edits.
    const rowKey = series ? `edit:${series.id}` : 'create';

    useEffect(() => {
        const next = buildDefaults(series);
        form.setDefaults(next);
        form.setData(next);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rowKey]);

    const preview = `${form.data.prefix}${String(
        form.data.next_number,
    ).padStart(Math.max(1, form.data.padding), '0')}`;

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        const onSuccess = () => {
            toast.success(
                isEdit
                    ? `Series '${form.data.label}' updated.`
                    : `Series '${form.data.label}' created.`,
            );
            onOpenChange(false);
        };

        if (isEdit && series) {
            form.put(seriesUpdate({ documentSeries: series.id }).url, {
                preserveScroll: true,
                preserveState: true,
                onSuccess,
            });

            return;
        }

        form.post(seriesStore().url, {
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
                        {isEdit ? series.label : 'New numbering series'}
                    </SheetTitle>
                    <SheetDescription>
                        Where document numbers come from. Numbers are issued in
                        sequence with no gaps — a document that fails to save
                        gives its number back.
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={handleSubmit}
                    className="flex min-h-0 flex-1 flex-col"
                    noValidate
                >
                    <div className="flex-1 space-y-4 overflow-y-auto p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="series-type">Document type</Label>
                            <Select
                                value={form.data.document_type}
                                onValueChange={(value) =>
                                    form.setData(
                                        'document_type',
                                        value as DocumentSeriesType,
                                    )
                                }
                            >
                                <SelectTrigger id="series-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {documentTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {TYPE_LABELS[type] ?? type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.document_type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="series-label">Name</Label>
                            <Input
                                id="series-label"
                                maxLength={120}
                                value={form.data.label}
                                onChange={(e) =>
                                    form.setData('label', e.target.value)
                                }
                                placeholder="Sales invoices 2026"
                                required
                            />
                            <InputError message={form.errors.label} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="series-prefix">Prefix</Label>
                                <Input
                                    id="series-prefix"
                                    maxLength={16}
                                    value={form.data.prefix}
                                    onChange={(e) =>
                                        form.setData('prefix', e.target.value)
                                    }
                                    placeholder="SI-"
                                    className="font-mono"
                                />
                                <InputError message={form.errors.prefix} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="series-padding">Digits</Label>
                                <Input
                                    id="series-padding"
                                    type="number"
                                    min={1}
                                    max={12}
                                    value={form.data.padding}
                                    onChange={(e) =>
                                        form.setData(
                                            'padding',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                                <InputError message={form.errors.padding} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="series-next">Next number</Label>
                                <Input
                                    id="series-next"
                                    type="number"
                                    min={1}
                                    value={form.data.next_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'next_number',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                                <InputError message={form.errors.next_number} />
                            </div>
                        </div>

                        <p className="text-xs text-muted-foreground">
                            The next document will be numbered{' '}
                            <span className="font-mono">{preview}</span>.
                        </p>

                        <Separator />

                        <div>
                            <p className="text-sm font-medium">
                                Authority To Print
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Optional. Fill these in once the BIR permit is
                                issued — the printed document shows the permit
                                details only when they exist, and numbering
                                stops at the end of an authorised range rather
                                than issuing a serial nobody approved.
                            </p>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="series-atp">
                                    Permit number
                                </Label>
                                <Input
                                    id="series-atp"
                                    maxLength={64}
                                    value={form.data.atp_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'atp_number',
                                            e.target.value,
                                        )
                                    }
                                    className="font-mono"
                                />
                                <InputError message={form.errors.atp_number} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="series-permit-date">
                                    Permit date
                                </Label>
                                <Input
                                    id="series-permit-date"
                                    type="date"
                                    value={form.data.permit_issued_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'permit_issued_at',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.permit_issued_at}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="series-start">
                                    Range starts at
                                </Label>
                                <Input
                                    id="series-start"
                                    type="number"
                                    min={1}
                                    value={form.data.serial_start ?? ''}
                                    onChange={(e) =>
                                        form.setData(
                                            'serial_start',
                                            optionalNumber(e.target.value),
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.serial_start}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="series-end">
                                    Range ends at
                                </Label>
                                <Input
                                    id="series-end"
                                    type="number"
                                    min={1}
                                    value={form.data.serial_end ?? ''}
                                    onChange={(e) =>
                                        form.setData(
                                            'serial_end',
                                            optionalNumber(e.target.value),
                                        )
                                    }
                                />
                                <InputError message={form.errors.serial_end} />
                            </div>
                        </div>

                        <Separator />

                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <Label
                                    htmlFor="series-active"
                                    className="font-normal"
                                >
                                    Active
                                </Label>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Deactivating stops this series issuing new
                                    numbers. It is never deleted — the record of
                                    which serials went out has to survive.
                                </p>
                            </div>
                            <Switch
                                id="series-active"
                                checked={form.data.is_active}
                                onCheckedChange={(checked) =>
                                    form.setData('is_active', checked)
                                }
                            />
                        </div>
                    </div>

                    <SheetFooter className="border-t">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={handleCancel}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save series'
                                  : 'Create series'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
