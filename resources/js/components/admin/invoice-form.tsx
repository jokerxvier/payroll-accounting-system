import { Link, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { CalendarClock, Plus, Trash2 } from 'lucide-react';
import {
    useEffect,
    useImperativeHandle,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { FormEvent, Ref } from 'react';
import { toast } from 'sonner';
import { ContactEditSheet } from '@/components/admin/contact-edit-sheet';
import { CounterpartyPicker } from '@/components/admin/counterparty-picker';
import InputError from '@/components/input-error';
import { Money } from '@/components/money';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DatePicker } from '@/components/ui/date-picker';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    amountInputToCentavos,
    centavosToAmountInput,
    formatAmountInput,
    isAmountInput,
} from '@/lib/money-input';
import { index as contactsIndex } from '@/routes/admin/contacts';
import {
    index as invoiceIndex,
    store as invoiceStore,
    update as invoiceUpdate,
} from '@/routes/admin/invoices';
import type {
    InvoiceAccountOption,
    InvoiceContactOption,
    InvoiceEditable,
    InvoiceFormOptions,
    InvoiceLineDraft,
    InvoiceStudentOption,
    InvoiceTaxRateOption,
    InvoiceType,
    RecurringFrequency,
} from '@/types';
import { RECURRING_FREQUENCY_LABELS } from '@/types';

/**
 * Line editor for an invoice or a bill.
 *
 * The totals panel shows the three BIR sales buckets separately rather than
 * one subtotal, because that is the shape of the document being produced —
 * an operator adding an exempt line should watch it land in VAT-Exempt
 * Sales, not disappear into an aggregate.
 *
 * Those figures are a preview and say so. The server recomputes from the
 * lines on every save and again at approval, so this arithmetic is never
 * what gets stored; it exists so the operator can see the shape of the
 * document before committing to it.
 */

type Mode =
    | { kind: 'create'; type: InvoiceType }
    | { kind: 'edit'; invoice: InvoiceEditable };

/**
 * What the create page can ask of the form.
 *
 * The demo-fill button sits in the page header beside Back, but the state it
 * fills lives in here. A handle is the smallest seam that keeps both where
 * they belong.
 */
export interface InvoiceFormHandle {
    fillWithDemoData: () => void;
}

interface InvoiceFormProps extends InvoiceFormOptions {
    mode: Mode;
    ref?: Ref<InvoiceFormHandle>;
}

interface FormShape {
    type: InvoiceType;
    /** Who the charges are for. Null on an invoice raised without a student. */
    lms_student_id: number | null;
    contact_id: number | null;
    reference: string;
    issue_date: string;
    due_date: string;
    is_vat_inclusive: boolean;
    notes: string;
    terms: string;
    lines: InvoiceLineDraft[];
    /** Whether this invoice also sets up a schedule. Create + sales only. */
    repeat: boolean;
    recurrence: RecurrenceDraft;
    [key: string]:
        | string
        | number
        | boolean
        | null
        | InvoiceLineDraft[]
        | RecurrenceDraft;
}

/**
 * The only part of a schedule the operator types.
 *
 * Everything else a `pas_recurring_invoices` row needs is derived server-side
 * from this invoice: the day of the month from its issue date, the payment
 * terms from the gap to its due date, the payer, the student and the lines
 * from the document itself. Asking twice is how the two came to disagree.
 */
interface RecurrenceDraft {
    frequency: RecurringFrequency;
    name: string;
    ends_on: string;
}

/**
 * Descriptions the demo filler draws from, per document type. Plausible
 * school charges rather than "Test line 1", so a filled invoice can be shown
 * to someone without explaining it first.
 */
const DEMO_DESCRIPTIONS: Record<InvoiceType, string[]> = {
    sales: [
        'Tuition fee — Grade 7, first quarter',
        'Registration fee',
        'Laboratory fee',
        'Books and learning materials',
        'School bus service — one term',
        'Graduation fee',
        'Athletics and activities fee',
    ],
    purchase: [
        'Classroom supplies',
        'Photocopier lease — monthly',
        'Janitorial services',
        'Textbook delivery',
        'IT support retainer',
        'Electricity — monthly billing',
    ],
};

function pick<T>(options: T[]): T {
    return options[Math.floor(Math.random() * options.length)] as T;
}

/**
 * A different plausible draft each time, rather than one fixed fixture — a
 * filler that always produces the same invoice stops exercising the form
 * after the first click.
 *
 * Returns null when the school has no contacts or no accounts yet: there is
 * nothing to compose a document out of, and a half-filled form is worse than
 * an untouched one.
 */
function composeDemoDraft(
    type: InvoiceType,
    contactOptions: InvoiceContactOption[],
    accountOptions: InvoiceAccountOption[],
    taxRateOptions: InvoiceTaxRateOption[],
): Pick<
    FormShape,
    'contact_id' | 'reference' | 'issue_date' | 'due_date' | 'lines'
> | null {
    if (contactOptions.length === 0 || accountOptions.length === 0) {
        return null;
    }

    const issued = new Date();
    const due = new Date(issued);
    due.setDate(due.getDate() + pick([7, 15, 30, 30, 45]));

    const lineCount = pick([1, 1, 2, 2, 3]);
    const lines: InvoiceLineDraft[] = Array.from({ length: lineCount }, () => ({
        description: pick(DEMO_DESCRIPTIONS[type]),
        quantity: String(pick([1, 1, 1, 2, 5, 10])),
        unit_price_centavos: pick([500, 1200, 2500, 7500, 15000]) * 100,
        account_id: pick(accountOptions).id,
        tax_rate_id: taxRateOptions.length > 0 ? pick(taxRateOptions).id : null,
    }));

    return {
        contact_id: pick(contactOptions).id,
        reference: `${type === 'sales' ? 'PO' : 'SO'}-${String(
            Math.floor(Math.random() * 9000) + 1000,
        )}`,
        issue_date: issued.toISOString().slice(0, 10),
        due_date: due.toISOString().slice(0, 10),
        lines,
    };
}

/**
 * The students a contact is recorded as paying for.
 *
 * A contact is a parent or guardian exactly when this comes back non-empty —
 * `relationship` is free text off the LMS ("Mother", "Guardian", "Tita"), so
 * the link in `pas_contact_students` is the only trustworthy test.
 */
function studentsPaidForBy(
    students: InvoiceStudentOption[],
    contactId: number | null,
): InvoiceStudentOption[] {
    if (contactId === null) {
        return [];
    }

    return students.filter((student) =>
        student.payers.some((payer) => payer.contact_id === contactId),
    );
}

function emptyLine(): InvoiceLineDraft {
    return {
        description: '',
        quantity: '1',
        unit_price_centavos: 0,
        account_id: null,
        tax_rate_id: null,
    };
}

/**
 * Today, where the operator is.
 *
 * NOT `toISOString().slice(0, 10)`: that converts to UTC, so any Manila
 * morning before 08:00 resolves to yesterday and the invoice defaults to the
 * wrong day. Harmless-looking until a schedule reads its cadence day off it
 * (RULES.md §Date inputs).
 */
function today(): string {
    return format(new Date(), 'yyyy-MM-dd');
}

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            type: mode.type,
            lms_student_id: null,
            contact_id: null,
            reference: '',
            issue_date: today(),
            due_date: '',
            is_vat_inclusive: false,
            notes: '',
            terms: '',
            lines: [emptyLine()],
            repeat: false,
            recurrence: emptyRecurrence(),
        };
    }

    const invoice = mode.invoice;

    return {
        type: invoice.type,
        lms_student_id: invoice.lms_student_id ?? null,
        contact_id: invoice.contact_id,
        reference: invoice.reference ?? '',
        issue_date: invoice.issue_date,
        due_date: invoice.due_date ?? '',
        is_vat_inclusive: invoice.is_vat_inclusive,
        notes: invoice.notes ?? '',
        terms: invoice.terms ?? '',
        lines: invoice.lines.length > 0 ? invoice.lines : [emptyLine()],
        // Edit never offers it: turning a draft into a schedule after the fact
        // is a different question from the one the create form asks, and the
        // server refuses the field here anyway.
        repeat: false,
        recurrence: emptyRecurrence(),
    };
}

function emptyRecurrence(): RecurrenceDraft {
    return { frequency: 'monthly', name: '', ends_on: '' };
}

const MONTHS_PER_FREQUENCY: Record<RecurringFrequency, number> = {
    monthly: 1,
    quarterly: 3,
    annually: 12,
};

/** 'YYYY-MM-DD' as a local Date, or null when it is not a date yet. */
function parseIssueDate(value: string): Date | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);
    const parsed = new Date(year, month - 1, day);

    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

/**
 * Add months without letting the day stick.
 *
 * Mirrors `RecurringInvoice::onDayOf()`: the 31st clamps to a short month's
 * last day and returns to the 31st afterwards, rather than dragging 28
 * forward for the rest of the year. JavaScript's own `setMonth` rolls over
 * instead — 31 January plus a month is 3 March — which would show the
 * operator a date the server will never bill.
 */
function addMonthsKeepingDay(from: Date, months: number): Date {
    const day = from.getDate();
    const target = new Date(from.getFullYear(), from.getMonth() + months, 1);
    const lastDay = new Date(
        target.getFullYear(),
        target.getMonth() + 1,
        0,
    ).getDate();

    return new Date(
        target.getFullYear(),
        target.getMonth(),
        Math.min(day, lastDay),
    );
}

/** The gap the schedule inherits as its payment terms, or null for on receipt. */
function dueDaysBetween(issueDate: string, dueDate: string): number | null {
    const issued = parseIssueDate(issueDate);
    const due = parseIssueDate(dueDate);

    if (issued === null || due === null) {
        return null;
    }

    return Math.max(
        0,
        Math.round((due.getTime() - issued.getTime()) / 86_400_000),
    );
}

function formatLongDate(date: Date): string {
    return date.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

/**
 * Quantity, not money: signed and to four places, matching decimal(12,4).
 * Gated on each keystroke for the same reason the amount boxes are — see
 * `@/lib/money-input`.
 */
const QUANTITY_PATTERN = /^-?\d*\.?\d{0,4}$/;

function quantityToNumber(input: string): number {
    const parsed = Number(input.trim());

    return Number.isNaN(parsed) ? 0 : parsed;
}

/**
 * Which sales bucket a line falls in, mirroring
 * InvoiceTotalsCalculator::bucketFor(). A line with no rate is treated as
 * exempt, and a VAT rate at 0 bps stays in VATable — the preview must agree
 * with the server rather than quietly presenting a tidier answer.
 */
function bucketFor(rate: InvoiceTaxRateOption | undefined): string {
    if (rate === undefined) {
        return 'exempt';
    }

    if (rate.type === 'exempt' || rate.type === 'zero_rated') {
        return rate.type;
    }

    return 'vatable';
}

export function InvoiceForm({
    mode,
    ref,
    contactOptions,
    accountOptions,
    taxRateOptions,
    studentOptions = [],
    canCreateContact = false,
    receivableAccountOptions = [],
    payableAccountOptions = [],
}: InvoiceFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));
    const isEdit = mode.kind === 'edit';
    const isSales = form.data.type === 'sales';
    const counterpartyNoun = isSales ? 'customer' : 'supplier';
    const hasNoContacts = contactOptions.length === 0;

    const selectedContact =
        contactOptions.find((c) => c.id === form.data.contact_id) ?? null;

    /*
     * The customer is chosen first, and the students follow from it.
     *
     * A contact with no links is an organisation being billed for facility
     * hire; showing it an empty Student picker would read as missing data
     * rather than as a field that does not apply.
     *
     * The server re-checks the pairing regardless: {@see InvoiceRequest}
     * rejects a payer who is not linked to the student, because a stale
     * selection can survive a change of customer and billing a stranger's
     * child is not a mistake worth discovering from the family.
     */
    const studentsForContact = useMemo(
        () => studentsPaidForBy(studentOptions, form.data.contact_id),
        [studentOptions, form.data.contact_id],
    );

    const isGuardian = studentsForContact.length > 0;

    const selectedStudent =
        studentsForContact.find(
            (s) => s.lms_student_id === form.data.lms_student_id,
        ) ?? null;

    /** How this contact is related to the student — for the hint line. */
    const relationshipTo = (student: InvoiceStudentOption): string | null =>
        student.payers.find((p) => p.contact_id === form.data.contact_id)
            ?.relationship ?? null;

    const chooseContact = (contactId: number): void => {
        const students = studentsPaidForBy(studentOptions, contactId);

        form.setData((previous) => ({
            ...previous,
            contact_id: contactId,
            // A guardian with exactly one child needs no second decision, so
            // that child loads with them. Otherwise the field is cleared:
            // carrying the previous customer's student across would submit a
            // pairing the server is about to reject.
            lms_student_id:
                students.length === 1 ? students[0].lms_student_id : null,
        }));
    };

    const chooseStudent = (value: string): void => {
        form.setData('lms_student_id', value === '' ? null : Number(value));
    };

    const [contactSheetOpen, setContactSheetOpen] = useState(false);

    /*
     * A contact raised from the picker arrives through the page's props, not
     * through the sheet: `ContactController@store` redirects back here and
     * Inertia re-renders this page with a refreshed `contactOptions` while
     * `preserveState` keeps the half-typed draft. The new row is the id that
     * was not in the list a moment ago — one round trip creates at most one
     * contact, so that identifies it without the server having to tell us.
     *
     * Selecting it is the whole point of raising it, and it goes through
     * `chooseContact` so a new guardian resolves their student like any other.
     */
    const knownContactIds = useRef<Set<number>>(
        new Set(contactOptions.map((option) => option.id)),
    );

    useEffect(() => {
        const known = knownContactIds.current;
        const added = contactOptions.filter((option) => !known.has(option.id));

        contactOptions.forEach((option) => known.add(option.id));

        if (added.length > 0) {
            chooseContact(added[added.length - 1].id);
        }
        // chooseContact closes over `form`, which is a new object every
        // render; depending on it would re-run this after every keystroke.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [contactOptions]);

    // The price inputs are driven by their own raw text, not by the parsed
    // centavos. Deriving the displayed value from the parsed number means
    // every keystroke reformats it — typing "5" becomes "5.00" and the next
    // character lands after the decimals — so a multi-digit figure cannot be
    // typed at all. Same fix journal-entry-form.tsx carries, and the reason
    // its tests now drive input character by character.
    const [rawPrices, setRawPrices] = useState<string[]>(() =>
        form.data.lines.map((line) =>
            centavosToAmountInput(line.unit_price_centavos),
        ),
    );

    const rateById = useMemo(() => {
        const map = new Map<number, InvoiceTaxRateOption>();
        taxRateOptions.forEach((rate) => map.set(rate.id, rate));

        return map;
    }, [taxRateOptions]);

    /**
     * A preview of the document face. Mirrors InvoiceTotalsCalculator:
     * rounding happens once per line, never on the total, so what is shown
     * here matches what the server will store to the centavo.
     */
    const totals = useMemo(() => {
        let vatable = 0;
        let exempt = 0;
        let zeroRated = 0;
        let vat = 0;

        form.data.lines.forEach((line) => {
            const rate =
                line.tax_rate_id === null
                    ? undefined
                    : rateById.get(line.tax_rate_id);

            const extended = Math.round(
                line.unit_price_centavos * quantityToNumber(line.quantity),
            );

            const postsTax =
                rate !== undefined &&
                rate.rate_bps > 0 &&
                (rate.type === 'vat_sales' || rate.type === 'vat_purchase');

            let net = extended;
            let tax = 0;

            if (postsTax) {
                if (form.data.is_vat_inclusive) {
                    tax = Math.round(
                        (extended * rate.rate_bps) / (10000 + rate.rate_bps),
                    );
                    net = extended - tax;
                } else {
                    tax = Math.round((extended * rate.rate_bps) / 10000);
                }
            }

            const bucket = bucketFor(rate);

            if (bucket === 'exempt') {
                exempt += net;
            } else if (bucket === 'zero_rated') {
                zeroRated += net;
            } else {
                vatable += net;
            }

            vat += tax;
        });

        return {
            vatable,
            exempt,
            zeroRated,
            vat,
            total: vatable + exempt + zeroRated + vat,
        };
    }, [form.data.lines, form.data.is_vat_inclusive, rateById]);

    const updateLine = (
        index: number,
        patch: Partial<InvoiceLineDraft>,
    ): void => {
        form.setData(
            'lines',
            form.data.lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );
    };

    const setPrice = (index: number, input: string): void => {
        if (!isAmountInput(input)) {
            return;
        }

        setRawPrices((prev) =>
            prev.map((value, i) => (i === index ? input : value)),
        );
        updateLine(index, {
            unit_price_centavos: amountInputToCentavos(input),
        });
    };

    /** Group and pad once focus leaves — see `setAmount` in the payment form. */
    const formatPriceOnBlur = (index: number): void => {
        setRawPrices((prev) =>
            prev.map((value, i) =>
                i === index ? formatAmountInput(value) : value,
            ),
        );
    };

    const setQuantity = (index: number, input: string): void => {
        if (!QUANTITY_PATTERN.test(input)) {
            return;
        }

        updateLine(index, { quantity: input });
    };

    const addLine = (): void => {
        form.setData('lines', [...form.data.lines, emptyLine()]);
        setRawPrices((prev) => [...prev, '']);
    };

    const fillWithDemoData = (): void => {
        const draft = composeDemoDraft(
            form.data.type,
            contactOptions,
            accountOptions,
            taxRateOptions,
        );

        if (draft === null) {
            return;
        }

        // The filler picks a customer at random, so it goes through the same
        // resolution the picker does: a student left over from a previous
        // choice belongs to a different family, and the server rejects that
        // pairing rather than saving the draft.
        const students = studentsPaidForBy(studentOptions, draft.contact_id);

        form.setData((previous) => ({
            ...previous,
            contact_id: draft.contact_id,
            lms_student_id:
                students.length === 1 ? students[0].lms_student_id : null,
            reference: draft.reference,
            issue_date: draft.issue_date,
            due_date: draft.due_date,
            lines: draft.lines,
        }));

        // The price inputs are driven by their own raw text, so filling
        // `unit_price_centavos` alone would leave every price cell blank
        // while the totals below it showed figures.
        setRawPrices(
            draft.lines.map((line) =>
                centavosToAmountInput(line.unit_price_centavos),
            ),
        );
    };

    useImperativeHandle(ref, () => ({ fillWithDemoData }));

    const removeLine = (index: number): void => {
        // An invoice needs at least one line, so the last one is emptied
        // rather than removed — leaving a lineless form with no way to add
        // the first line back would be a dead end.
        if (form.data.lines.length === 1) {
            form.setData('lines', [emptyLine()]);
            setRawPrices(['']);

            return;
        }

        form.setData(
            'lines',
            form.data.lines.filter((_, i) => i !== index),
        );
        setRawPrices((prev) => prev.filter((_, i) => i !== index));
    };

    const handleSubmit = (event: FormEvent): void => {
        event.preventDefault();

        const options = {
            onError: () => {
                toast.error('Check the highlighted fields and try again.');
            },
        };

        if (isEdit) {
            form.put(invoiceUpdate({ invoice: mode.invoice.id }).url, options);

            return;
        }

        form.post(invoiceStore().url, options);
    };

    const documentNoun = isSales ? 'invoice' : 'bill';

    /*
     * Repeat is a create-time question about a sales invoice. A bill is the
     * supplier's own document, and an edit is asking about a draft that
     * already exists — the server refuses the field in both cases, so the
     * form does not offer it.
     */
    const showRepeat = isSales && !isEdit;

    const setRecurrence = (patch: Partial<RecurrenceDraft>): void => {
        form.setData((previous) => ({
            ...previous,
            recurrence: { ...previous.recurrence, ...patch },
        }));
    };

    /** What the server will call the schedule if the name is left blank. */
    const derivedScheduleName = ((): string => {
        const payer = selectedContact?.name ?? 'Recurring invoice';
        const first = form.data.lines[0]?.description ?? '';

        return first === '' ? payer : `${payer} — ${first}`;
    })();

    /*
     * The sentence under the cadence fields, built from this invoice's own
     * issue date — the same derivation `StartInvoiceSchedule` makes on the
     * server. Reading the next date back is how an operator catches a wrong
     * issue date before it becomes a standing instruction.
     */
    const repeatSummary = ((): string => {
        const issued = parseIssueDate(form.data.issue_date);

        if (issued === null) {
            return 'Set an issue date and this will say when the next invoice falls.';
        }

        const months = MONTHS_PER_FREQUENCY[form.data.recurrence.frequency];
        const next = addMonthsKeepingDay(issued, months);
        const terms = dueDaysBetween(form.data.issue_date, form.data.due_date);

        return [
            `Repeats ${RECURRING_FREQUENCY_LABELS[
                form.data.recurrence.frequency
            ].toLowerCase()} on day ${issued.getDate()}.`,
            `Next invoice ${formatLongDate(next)}.`,
            terms === null
                ? 'Each one is due on receipt, like this one.'
                : `Each one is payable within ${terms} ${terms === 1 ? 'day' : 'days'}, like this one.`,
        ].join(' ');
    })();

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Details</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    {/*
                      The counterparty comes first, because everything else on
                      the header follows from it: whether there is a student to
                      ask about at all, and which students may legally be
                      billed to this payer. A register runs to hundreds of
                      names, so the picker is a searchable combobox rather than
                      a plain Select — scrolling to "Villanueva" is not a
                      reasonable ask.
                    */}
                    <div className="space-y-2">
                        <Label htmlFor="contact_id">
                            {isSales ? 'Customer' : 'Supplier'}
                        </Label>
                        <CounterpartyPicker
                            id="contact_id"
                            noun={counterpartyNoun}
                            options={contactOptions}
                            value={form.data.contact_id}
                            // An empty register no longer has to be a dead
                            // end: if this operator may create a contact, the
                            // picker opens onto the New button rather than
                            // refusing to open at all.
                            disabled={hasNoContacts && !canCreateContact}
                            onSelect={chooseContact}
                            onAddNew={
                                canCreateContact
                                    ? () => setContactSheetOpen(true)
                                    : undefined
                            }
                        />
                        {isSales && isGuardian && (
                            <p className="text-sm text-muted-foreground">
                                {selectedContact?.name ?? 'This customer'} is
                                recorded as a guardian
                                {studentsForContact.length === 1
                                    ? ' — their student is loaded below.'
                                    : ` of ${studentsForContact.length} students — choose which one below.`}
                            </p>
                        )}
                        {/*
                          An empty picker and a picker with nothing matching
                          the filter look identical, and only one of them has
                          an obvious next action. Contacts are deliberately not
                          cloned to a new school (rules/PLAN.md §5 Slice 4), so
                          "none yet" is the normal state on day one rather than
                          a fault — and a contact flagged only Supplier is
                          invisible here, which is the case most likely to read
                          as a bug. Name the gap, name the fix.
                        */}
                        {hasNoContacts && (
                            <p className="text-sm text-muted-foreground">
                                Add a contact and tick{' '}
                                <span className="font-medium">
                                    {isSales ? 'Customer' : 'Supplier'}
                                </span>{' '}
                                to {isSales ? 'invoice' : 'bill'} them.{' '}
                                <Link
                                    href={contactsIndex()}
                                    className="font-medium underline underline-offset-4"
                                >
                                    Go to contacts
                                </Link>
                            </p>
                        )}
                        <InputError message={form.errors.contact_id} />
                    </div>

                    {/*
                      Only for a parent or guardian, and only on a sale. An
                      organisation being billed for facility hire has no pupil
                      behind it, and neither has a supplier — offering either of
                      them an empty Student picker reads as missing data rather
                      than as a field that does not apply. The list is narrowed
                      to this payer's own children, which is also the pairing
                      the server will accept.
                    */}
                    {isSales && isGuardian && (
                        <div className="space-y-2">
                            <Label htmlFor="lms_student_id">Student</Label>
                            <Select
                                value={
                                    form.data.lms_student_id === null
                                        ? ''
                                        : String(form.data.lms_student_id)
                                }
                                onValueChange={chooseStudent}
                            >
                                <SelectTrigger
                                    id="lms_student_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a student — optional" />
                                </SelectTrigger>
                                <SelectContent>
                                    {studentsForContact.map((student) => (
                                        <SelectItem
                                            key={student.lms_student_id}
                                            value={String(
                                                student.lms_student_id,
                                            )}
                                        >
                                            {student.name}
                                            {relationshipTo(student)
                                                ? ` · ${relationshipTo(student)}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {selectedStudent && (
                                <p className="text-sm text-muted-foreground">
                                    Charges are for {selectedStudent.name}.
                                </p>
                            )}
                            <InputError message={form.errors.lms_student_id} />
                        </div>
                    )}

                    {/*
                      Three short fields on one row at their own widths. A
                      64-character reference and a ten-character date do not
                      want half a page each, and the 2-column grid this
                      replaced left the due date orphaned beside a gap.
                    */}
                    <div className="flex flex-wrap items-start gap-4">
                        <div className="w-full min-w-56 flex-1 space-y-2">
                            <Label htmlFor="reference">Reference</Label>
                            <Input
                                id="reference"
                                value={form.data.reference}
                                maxLength={64}
                                onChange={(e) =>
                                    form.setData('reference', e.target.value)
                                }
                                placeholder={
                                    isSales
                                        ? 'Student or PO reference'
                                        : "The supplier's own document number"
                                }
                            />
                            <InputError message={form.errors.reference} />
                        </div>

                        {/*
                      DatePicker, not `<Input type="date">` — the native
                      control renders differently in every browser, ignores the
                      theme, and cannot be cleared once set, which is exactly
                      what a due date on "due on receipt" terms needs
                      (CODING_STANDARDS_REACT.md §Date inputs).
                    */}
                        <div className="w-full space-y-2 sm:w-48">
                            <Label htmlFor="issue_date">Issue date</Label>
                            <DatePicker
                                id="issue_date"
                                value={form.data.issue_date}
                                onChange={(value) =>
                                    form.setData('issue_date', value)
                                }
                                placeholder="Pick a date"
                                ariaInvalid={!!form.errors.issue_date}
                            />
                            <InputError message={form.errors.issue_date} />
                        </div>

                        <div className="w-full space-y-2 sm:w-48">
                            <Label htmlFor="due_date">Due date</Label>
                            <DatePicker
                                id="due_date"
                                value={form.data.due_date}
                                onChange={(value) =>
                                    form.setData('due_date', value)
                                }
                                placeholder="No due date"
                                ariaInvalid={!!form.errors.due_date}
                            />
                            <InputError message={form.errors.due_date} />
                        </div>
                    </div>

                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="is_vat_inclusive"
                            checked={form.data.is_vat_inclusive}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'is_vat_inclusive',
                                    checked === true,
                                )
                            }
                        />
                        <div className="space-y-1">
                            <Label
                                htmlFor="is_vat_inclusive"
                                className="font-normal"
                            >
                                Prices include VAT
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Tick this when the prices entered are what the
                                customer pays. The VAT is extracted from them
                                rather than added on top — the totals come out
                                the same either way.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/*
              Setting the same invoice to come round again.
              
              Sales only, and only while raising a new one: turning an existing
              draft into a schedule is a different question, and the server
              refuses the field on an edit. Off by default — most invoices are
              raised once, and a form that quietly commits a school to billing
              a family every month would be a bad default to get wrong.
            */}
            {showRepeat ? (
                <Card>
                    <CardHeader>
                        <CardTitle>Repeat</CardTitle>
                        <CardDescription>
                            Bill this again on a cadence, without typing it out
                            each time.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="repeat"
                                checked={form.data.repeat}
                                onCheckedChange={(checked) =>
                                    form.setData('repeat', checked === true)
                                }
                            />
                            <div className="space-y-1">
                                <Label htmlFor="repeat" className="font-normal">
                                    Repeat this invoice
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    This invoice is still raised now. Future
                                    ones appear overnight as drafts you approve
                                    — nothing reaches the ledger on its own.
                                </p>
                            </div>
                        </div>

                        {form.data.repeat ? (
                            <div className="space-y-4 border-t pt-4">
                                {/*
                                  Widths are chosen per control rather than
                                  split down the card. A cadence is one short
                                  word and a date is ten characters; stretching
                                  either across half a page makes the row hard
                                  to read and the targets absurd. They wrap to
                                  full width on a narrow screen.
                                */}
                                <div className="flex flex-wrap items-start gap-4">
                                    <div className="w-full space-y-2 sm:w-44">
                                        <Label htmlFor="recurrence_frequency">
                                            Every
                                        </Label>
                                        <Select
                                            value={
                                                form.data.recurrence.frequency
                                            }
                                            onValueChange={(value) =>
                                                setRecurrence({
                                                    frequency:
                                                        value as RecurringFrequency,
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                id="recurrence_frequency"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    RECURRING_FREQUENCY_LABELS,
                                                ).map(([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={
                                                form.errors[
                                                    'recurrence.frequency'
                                                ] ?? form.errors.recurrence
                                            }
                                        />
                                    </div>

                                    <div className="w-full space-y-2 sm:w-56">
                                        <Label htmlFor="recurrence_ends_on">
                                            Until
                                        </Label>
                                        <DatePicker
                                            id="recurrence_ends_on"
                                            value={form.data.recurrence.ends_on}
                                            onChange={(value) =>
                                                setRecurrence({
                                                    ends_on: value,
                                                })
                                            }
                                            placeholder="Runs until paused"
                                            ariaInvalid={
                                                !!form.errors[
                                                    'recurrence.ends_on'
                                                ]
                                            }
                                        />
                                        <InputError
                                            message={
                                                form.errors[
                                                    'recurrence.ends_on'
                                                ]
                                            }
                                        />
                                    </div>

                                    <div className="w-full min-w-56 flex-1 space-y-2">
                                        <Label htmlFor="recurrence_name">
                                            Name this schedule
                                        </Label>
                                        <Input
                                            id="recurrence_name"
                                            value={form.data.recurrence.name}
                                            maxLength={120}
                                            placeholder={derivedScheduleName}
                                            onChange={(e) =>
                                                setRecurrence({
                                                    name: e.target.value,
                                                })
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            How it appears in the schedule list.
                                            Leave it blank to use the
                                            suggestion.
                                        </p>
                                        <InputError
                                            message={
                                                form.errors['recurrence.name']
                                            }
                                        />
                                    </div>
                                </div>

                                {/*
                                  What the operator is actually agreeing to.
                                  The cadence day and the payment terms are
                                  read off this invoice rather than asked for
                                  again, so a derivation they cannot see is one
                                  they cannot check — which is why this is the
                                  loudest thing on the card rather than a
                                  caption under it.
                                */}
                                <div className="flex items-start gap-3 rounded-md border border-primary/20 bg-primary/5 p-3">
                                    <CalendarClock
                                        className="mt-0.5 h-4 w-4 shrink-0 text-primary"
                                        aria-hidden
                                    />
                                    <p className="text-sm">{repeatSummary}</p>
                                </div>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            ) : null}

            <Card>
                <CardHeader>
                    <CardTitle>Lines</CardTitle>
                    <CardDescription>
                        Each line posts its net to the account you choose. VAT
                        goes to the tax account, never to income.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4 p-0 sm:p-0">
                    <div className="overflow-x-auto">
                        <Table className="text-sm">
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Description
                                    </TableHead>
                                    <TableHead className="w-[6rem] text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Qty
                                    </TableHead>
                                    <TableHead className="w-[9rem] text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Unit price
                                    </TableHead>
                                    <TableHead className="w-[13rem] text-xs tracking-wide text-muted-foreground uppercase">
                                        {isSales
                                            ? 'Income account'
                                            : 'Expense account'}
                                    </TableHead>
                                    <TableHead className="w-[10rem] text-xs tracking-wide text-muted-foreground uppercase">
                                        VAT
                                    </TableHead>
                                    <TableHead className="sr-only">
                                        Remove
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {form.data.lines.map((line, index) => (
                                    <TableRow key={index}>
                                        <TableCell>
                                            <Input
                                                value={line.description}
                                                maxLength={255}
                                                aria-label={`Line ${index + 1} description`}
                                                onChange={(e) =>
                                                    updateLine(index, {
                                                        description:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `lines.${index}.description`
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                value={line.quantity}
                                                inputMode="decimal"
                                                className="text-right tabular-nums"
                                                aria-label={`Line ${index + 1} quantity`}
                                                onChange={(e) =>
                                                    setQuantity(
                                                        index,
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `lines.${index}.quantity`
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                value={rawPrices[index] ?? ''}
                                                inputMode="decimal"
                                                placeholder="0.00"
                                                className="text-right tabular-nums"
                                                aria-label={`Line ${index + 1} unit price`}
                                                onChange={(e) =>
                                                    setPrice(
                                                        index,
                                                        e.target.value,
                                                    )
                                                }
                                                onBlur={() =>
                                                    formatPriceOnBlur(index)
                                                }
                                            />
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `lines.${index}.unit_price_centavos`
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                value={
                                                    line.account_id === null
                                                        ? ''
                                                        : String(
                                                              line.account_id,
                                                          )
                                                }
                                                onValueChange={(value) =>
                                                    updateLine(index, {
                                                        account_id:
                                                            Number(value),
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`Line ${index + 1} account`}
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Account" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {accountOptions.map(
                                                        (account) => (
                                                            <SelectItem
                                                                key={account.id}
                                                                value={String(
                                                                    account.id,
                                                                )}
                                                            >
                                                                {account.code} ·{' '}
                                                                {account.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `lines.${index}.account_id`
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                value={
                                                    line.tax_rate_id === null
                                                        ? ''
                                                        : String(
                                                              line.tax_rate_id,
                                                          )
                                                }
                                                onValueChange={(value) =>
                                                    updateLine(index, {
                                                        tax_rate_id:
                                                            Number(value),
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`Line ${index + 1} VAT treatment`}
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="None" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {taxRateOptions.map(
                                                        (rate) => (
                                                            <SelectItem
                                                                key={rate.id}
                                                                value={String(
                                                                    rate.id,
                                                                )}
                                                            >
                                                                {rate.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                aria-label={`Remove line ${index + 1}`}
                                                onClick={() =>
                                                    removeLine(index)
                                                }
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <div className="px-4 pb-4">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={addLine}
                        >
                            <Plus className="mr-1 h-4 w-4" />
                            Add line
                        </Button>
                        <InputError message={form.errors.lines} />
                    </div>
                </CardContent>
            </Card>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Notes</CardTitle>
                        <CardDescription>
                            Terms print on the document. Notes stay internal.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="terms">Terms</Label>
                            <textarea
                                id="terms"
                                rows={3}
                                value={form.data.terms}
                                onChange={(e) =>
                                    form.setData('terms', e.target.value)
                                }
                                placeholder="Printed on the document — payment terms, bank details, anything the customer needs."
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError message={form.errors.terms} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="notes">Internal notes</Label>
                            <textarea
                                id="notes"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="Not printed. For whoever picks this up next."
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError message={form.errors.notes} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Preview</CardTitle>
                        <CardDescription>
                            Recalculated on save, and again when the document is
                            approved.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            <TotalRow
                                label="VATable sales"
                                centavos={totals.vatable}
                            />
                            <TotalRow
                                label="VAT-exempt sales"
                                centavos={totals.exempt}
                            />
                            <TotalRow
                                label="Zero-rated sales"
                                centavos={totals.zeroRated}
                            />
                            <TotalRow label="VAT" centavos={totals.vat} />
                            <div className="flex items-center justify-between border-t pt-2 text-base font-medium">
                                <dt>Total</dt>
                                <dd>
                                    <Money amount={totals.total / 100} />
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save draft'
                          : `Save draft ${documentNoun}`}
                </Button>
                <Button asChild variant="ghost">
                    <Link
                        href={
                            invoiceIndex({
                                query: { type: form.data.type },
                            }).url
                        }
                    >
                        Cancel
                    </Link>
                </Button>
            </div>

            {/*
              The register's own sheet, not a second contact form. It posts to
              `contacts.store` like everywhere else, so a contact raised mid
              draft is validated by the same rules and carries the same
              defaults — and this form learns about it from the refreshed
              props rather than from a parallel code path.

              Rendered outside the fields but inside the <form> element; a
              Sheet portals its content to the body, so no nested form is
              produced.
            */}
            {canCreateContact && (
                <ContactEditSheet
                    open={contactSheetOpen}
                    onOpenChange={setContactSheetOpen}
                    defaultRole={isSales ? 'customer' : 'supplier'}
                    receivableAccountOptions={receivableAccountOptions}
                    payableAccountOptions={payableAccountOptions}
                />
            )}
        </form>
    );
}

function TotalRow({ label, centavos }: { label: string; centavos: number }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>
                <Money amount={centavos / 100} />
            </dd>
        </div>
    );
}
