import { Head, useForm } from '@inertiajs/react';
import {
    Check,
    ChevronsUpDown,
    Copy,
    KeyRound,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/**
 * Gateway credentials.
 *
 * The stored secret is never on this page. The server sends four masked
 * characters and a boolean; the field is write-only, and leaving it blank
 * keeps whatever is already stored. That is why there is no "reveal" control
 * — there is nothing here to reveal.
 */

interface AccountOption {
    id: number;
    code: string;
    name: string;
}

interface GatewayRow {
    provider: string;
    mode: string;
    publishable_key: string | null;
    secret_masked: string | null;
    has_secret: boolean;
    has_webhook_secret: boolean;
    cash_account_id: number | null;
    fee_account_id: number | null;
    is_active: boolean;
    is_usable: boolean;
}

interface ResolvedDefault {
    id: number;
    code: string;
    name: string;
}

interface Props {
    settings: GatewayRow[];
    cashAccountOptions: AccountOption[];
    expenseAccountOptions: AccountOption[];
    webhookBaseUrl: string;
    /** What a gateway posts through when nobody overrides it. */
    defaults: {
        cash: ResolvedDefault | null;
        fee: ResolvedDefault | null;
    };
}

/**
 * Sentinel for "use the school default", the same shape
 * `contact-edit-sheet.tsx` uses for its account overrides. A Select cannot
 * hold null, so the absence has to be spelled.
 */
const USE_DEFAULT = '__default__';

const PROVIDER_LABELS: Record<string, string> = {
    paymongo: 'PayMongo',
    stripe: 'Stripe',
};

function GatewayCard({
    row,
    cashAccountOptions,
    expenseAccountOptions,
    webhookBaseUrl,
    defaults,
}: {
    row: GatewayRow;
    cashAccountOptions: AccountOption[];
    expenseAccountOptions: AccountOption[];
    defaults: Props['defaults'];
    webhookBaseUrl: string;
}) {
    const [copied, setCopied] = useState(false);

    const form = useForm({
        provider: row.provider,
        mode: row.mode,
        publishable_key: row.publishable_key ?? '',
        // Always blank on load. The server never sends the value, and an
        // empty submission means "leave it alone".
        secret_key: '',
        webhook_secret: '',
        cash_account_id: row.cash_account_id,
        fee_account_id: row.fee_account_id,
        is_active: row.is_active,
    });

    const webhookUrl = `${webhookBaseUrl}/${row.provider}`;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/admin/payment-gateways', { preserveScroll: true });
    };

    const copyWebhook = () => {
        void navigator.clipboard.writeText(webhookUrl);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
                <CardTitle className="flex items-center gap-2">
                    {PROVIDER_LABELS[row.provider] ?? row.provider}
                    <Badge
                        variant={row.mode === 'live' ? 'default' : 'outline'}
                    >
                        {row.mode}
                    </Badge>
                </CardTitle>
                {row.is_active && (
                    <Badge variant={row.is_usable ? 'default' : 'destructive'}>
                        {row.is_usable
                            ? 'Taking payments'
                            : 'On, but incomplete'}
                    </Badge>
                )}
            </CardHeader>

            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor={`pk-${row.provider}-${row.mode}`}>
                                Publishable key
                            </Label>
                            <Input
                                id={`pk-${row.provider}-${row.mode}`}
                                value={form.data.publishable_key}
                                onChange={(e) =>
                                    form.setData(
                                        'publishable_key',
                                        e.target.value,
                                    )
                                }
                                placeholder={
                                    row.provider === 'stripe'
                                        ? 'pk_test_…'
                                        : 'pk_test_…'
                                }
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor={`sk-${row.provider}-${row.mode}`}>
                                Secret key
                            </Label>
                            <Input
                                id={`sk-${row.provider}-${row.mode}`}
                                type="password"
                                autoComplete="off"
                                value={form.data.secret_key}
                                onChange={(e) =>
                                    form.setData('secret_key', e.target.value)
                                }
                                placeholder={
                                    row.has_secret
                                        ? `Stored — ${row.secret_masked}`
                                        : 'sk_test_…'
                                }
                                aria-invalid={!!form.errors.secret_key}
                            />
                            <p className="text-xs text-muted-foreground">
                                {row.has_secret
                                    ? 'Leave blank to keep the stored key.'
                                    : 'Not set yet.'}
                            </p>
                            {form.errors.secret_key && (
                                <p className="text-sm text-destructive">
                                    {form.errors.secret_key}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor={`ws-${row.provider}-${row.mode}`}>
                                Webhook signing secret
                            </Label>
                            <Input
                                id={`ws-${row.provider}-${row.mode}`}
                                type="password"
                                autoComplete="off"
                                value={form.data.webhook_secret}
                                onChange={(e) =>
                                    form.setData(
                                        'webhook_secret',
                                        e.target.value,
                                    )
                                }
                                placeholder={
                                    row.has_webhook_secret
                                        ? 'Stored — leave blank to keep it'
                                        : 'whsec_…'
                                }
                                aria-invalid={!!form.errors.webhook_secret}
                            />
                            {form.errors.webhook_secret && (
                                <p className="text-sm text-destructive">
                                    {form.errors.webhook_secret}
                                </p>
                            )}
                        </div>
                    </div>

                    {/*
                      Where the money goes is a question with the same answer
                      for almost every school, so it is stated rather than
                      asked. The overrides stay reachable for a school that
                      renumbered its chart — same shape as a contact's
                      receivable account, where null means "use the default".
                    */}
                    <Collapsible>
                        <div className="rounded-md border p-3">
                            <div className="flex items-start justify-between gap-4">
                                {/*
                                  Two labelled lines rather than a sentence:
                                  the cards sit two-up, and a run-on sentence
                                  with two account names in it wraps in the
                                  middle of a name.
                                */}
                                <dl className="min-w-0 space-y-1 text-sm">
                                    <div className="flex flex-wrap gap-x-2">
                                        <dt className="text-muted-foreground">
                                            Settles into
                                        </dt>
                                        <dd className="font-medium">
                                            {defaults.cash
                                                ? `${defaults.cash.code} · ${defaults.cash.name}`
                                                : 'a cash account that is missing'}
                                        </dd>
                                    </div>
                                    <div className="flex flex-wrap gap-x-2">
                                        <dt className="text-muted-foreground">
                                            Fees expensed to
                                        </dt>
                                        <dd className="font-medium">
                                            {defaults.fee
                                                ? `${defaults.fee.code} · ${defaults.fee.name}`
                                                : 'an account that is missing'}
                                        </dd>
                                    </div>
                                </dl>
                                <CollapsibleTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="shrink-0 text-muted-foreground"
                                    >
                                        <ChevronsUpDown className="mr-1 size-3.5" />
                                        Advanced
                                    </Button>
                                </CollapsibleTrigger>
                            </div>

                            <CollapsibleContent className="mt-4 grid gap-4 sm:grid-cols-2 [&>div]:min-w-0">
                                <div className="space-y-2">
                                    <Label
                                        htmlFor={`cash-${row.provider}-${row.mode}`}
                                    >
                                        Settled money lands in
                                    </Label>
                                    <Select
                                        value={
                                            form.data.cash_account_id === null
                                                ? USE_DEFAULT
                                                : String(
                                                      form.data.cash_account_id,
                                                  )
                                        }
                                        onValueChange={(v) =>
                                            form.setData(
                                                'cash_account_id',
                                                v === USE_DEFAULT
                                                    ? null
                                                    : Number(v),
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id={`cash-${row.provider}-${row.mode}`}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={USE_DEFAULT}>
                                                Use the school default
                                            </SelectItem>
                                            {cashAccountOptions.map((a) => (
                                                <SelectItem
                                                    key={a.id}
                                                    value={String(a.id)}
                                                >
                                                    {a.code} · {a.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.data.cash_account_id === null &&
                                        defaults.cash && (
                                            <p className="text-xs text-muted-foreground">
                                                {defaults.cash.code} ·{' '}
                                                {defaults.cash.name}
                                            </p>
                                        )}
                                    {form.errors.cash_account_id && (
                                        <p className="text-sm text-destructive">
                                            {form.errors.cash_account_id}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label
                                        htmlFor={`fee-${row.provider}-${row.mode}`}
                                    >
                                        Gateway fee is expensed to
                                    </Label>
                                    <Select
                                        value={
                                            form.data.fee_account_id === null
                                                ? USE_DEFAULT
                                                : String(
                                                      form.data.fee_account_id,
                                                  )
                                        }
                                        onValueChange={(v) =>
                                            form.setData(
                                                'fee_account_id',
                                                v === USE_DEFAULT
                                                    ? null
                                                    : Number(v),
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id={`fee-${row.provider}-${row.mode}`}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={USE_DEFAULT}>
                                                Use the school default
                                            </SelectItem>
                                            {expenseAccountOptions.map((a) => (
                                                <SelectItem
                                                    key={a.id}
                                                    value={String(a.id)}
                                                >
                                                    {a.code} · {a.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.data.fee_account_id === null &&
                                        defaults.fee && (
                                            <p className="text-xs text-muted-foreground">
                                                {defaults.fee.code} ·{' '}
                                                {defaults.fee.name}
                                            </p>
                                        )}
                                    {form.errors.fee_account_id && (
                                        <p className="text-sm text-destructive">
                                            {form.errors.fee_account_id}
                                        </p>
                                    )}
                                </div>
                            </CollapsibleContent>
                        </div>
                    </Collapsible>

                    <div className="space-y-2 rounded-md border p-3">
                        <Label className="text-xs text-muted-foreground">
                            Webhook URL — paste this into the{' '}
                            {PROVIDER_LABELS[row.provider]} dashboard
                        </Label>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 overflow-x-auto rounded bg-muted px-2 py-1 font-mono text-xs">
                                {webhookUrl}
                            </code>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={copyWebhook}
                            >
                                {copied ? (
                                    <Check className="size-4" />
                                ) : (
                                    <Copy className="size-4" />
                                )}
                            </Button>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <Checkbox
                            id={`active-${row.provider}-${row.mode}`}
                            checked={form.data.is_active}
                            onCheckedChange={(c) =>
                                form.setData('is_active', c === true)
                            }
                        />
                        <Label htmlFor={`active-${row.provider}-${row.mode}`}>
                            Take payments through this
                        </Label>
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        Save {PROVIDER_LABELS[row.provider]} {row.mode}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

export default function PaymentGatewaysIndex({
    settings,
    cashAccountOptions,
    expenseAccountOptions,
    webhookBaseUrl,
    defaults,
}: Props) {
    return (
        <>
            <Head title="Payment gateways" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Payment gateways"
                    description="Credentials for taking invoice payments online. Each school uses its own merchant account, so the money settles into this school's bank."
                />

                <Alert>
                    <ShieldAlert className="size-4" />
                    <AlertTitle>Test and live are kept apart</AlertTitle>
                    <AlertDescription>
                        Only one mode per provider can be switched on at a time.
                        Turning live on turns test off, so a real card is never
                        charged from a sandbox by accident.
                    </AlertDescription>
                </Alert>

                {cashAccountOptions.length === 0 && (
                    <Alert variant="destructive">
                        <KeyRound className="size-4" />
                        <AlertTitle>No cash accounts to settle into</AlertTitle>
                        <AlertDescription>
                            Mark a bank account as a cash account in the chart
                            of accounts first — settled money has to land
                            somewhere.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    {settings.map((row) => (
                        <GatewayCard
                            key={`${row.provider}-${row.mode}`}
                            row={row}
                            cashAccountOptions={cashAccountOptions}
                            expenseAccountOptions={expenseAccountOptions}
                            webhookBaseUrl={webhookBaseUrl}
                            defaults={defaults}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

PaymentGatewaysIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/journal-entries' },
        { title: 'Payment gateways', href: '/admin/payment-gateways' },
    ],
};
