import { Link, useForm } from '@inertiajs/react';
import { CheckCircle2, Loader2, Plug, XCircle } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
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
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    index as schoolsIndex,
    store as schoolsStore,
    testConnection as schoolsTestConnection,
    update as schoolsUpdate,
} from '@/routes/admin/schools';
import type { ConnectionTestResult, School, SchoolFormData } from '@/types';

type Mode = { kind: 'create' } | { kind: 'edit'; school: School };

interface SchoolFormProps {
    mode: Mode;
}

interface FormShape extends SchoolFormData {
    [key: string]: string | number | boolean;
}

/**
 * Project a school name to a kebab-case slug that satisfies the
 * server-side `regex:/^[a-z0-9-]+$/` rule. Strips diacritics to ASCII
 * via `normalize('NFKD')` so "École Saint-Louis" becomes "ecole-saint-louis"
 * instead of dropping the accented character entirely.
 */
function toSlug(value: string): string {
    return value
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * Read the Laravel-issued XSRF-TOKEN cookie set by `EncryptCookies` +
 * `VerifyCsrfToken` middleware. This is the canonical CSRF transport for
 * Inertia SPAs — `app.blade.php` does not emit a `<meta name="csrf-token">`
 * tag, so the cookie path is the only one wired up. Returns null if the
 * cookie is missing (which means the session bootstrapped without
 * EncryptCookies — no CSRF header is sent and the request will 419).
 */
function getXsrfToken(): string | null {
    const match = document.cookie.match(/(^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[2]) : null;
}

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            name: '',
            slug: '',
            domain: '',
            lms_db_host: '127.0.0.1',
            lms_db_port: 3306,
            lms_db_database: '',
            lms_db_username: '',
            lms_db_password: '',
            lms_db_charset: 'utf8mb4',
            is_active: true,
        };
    }

    const row = mode.school;

    return {
        name: row.name,
        slug: row.slug,
        domain: row.domain ?? '',
        lms_db_host: row.lms_db_host,
        lms_db_port: row.lms_db_port,
        lms_db_database: row.lms_db_database,
        lms_db_username: row.lms_db_username,
        // Edit mode: empty means "keep current". The controller drops the
        // key from the update payload when blank so existing credentials
        // survive an edit that didn't touch the password field.
        lms_db_password: '',
        lms_db_charset: row.lms_db_charset,
        is_active: row.is_active,
    };
}

export function SchoolForm({ mode }: SchoolFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));
    const isEdit = mode.kind === 'edit';

    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState<ConnectionTestResult | null>(
        null,
    );
    // Slug auto-fills from name in create mode until the operator types in
    // the slug field. Edit mode starts dirty so a rename doesn't silently
    // overwrite the existing slug (which may already be referenced elsewhere).
    const [slugDirty, setSlugDirty] = useState(mode.kind === 'edit');

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (mode.kind === 'create') {
            form.post(schoolsStore().url, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`School '${form.data.name}' created.`);
                },
            });

            return;
        }

        form.put(schoolsUpdate({ school: mode.school.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`School '${form.data.name}' updated.`);
            },
        });
    };

    const handleTestConnection = async (): Promise<void> => {
        setTesting(true);
        setTestResult(null);

        try {
            const csrfToken = getXsrfToken();

            // Edit mode keeps the password field blank by default so a save
            // without typing a new password preserves the existing credential.
            // For the connection probe we need a real password — fall back to
            // the saved school's plaintext (already exposed in the props via
            // the model's `encrypted` cast). Create mode has no fallback;
            // the field validation will reject empty submissions.
            const password =
                form.data.lms_db_password ||
                (mode.kind === 'edit' ? mode.school.lms_db_password : '');

            const response = await fetch(schoolsTestConnection().url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
                },
                body: JSON.stringify({
                    lms_db_host: form.data.lms_db_host,
                    lms_db_port:
                        typeof form.data.lms_db_port === 'string'
                            ? Number(form.data.lms_db_port) || 0
                            : form.data.lms_db_port,
                    lms_db_database: form.data.lms_db_database,
                    lms_db_username: form.data.lms_db_username,
                    lms_db_password: password,
                    lms_db_charset: form.data.lms_db_charset,
                }),
            });

            if (!response.ok) {
                // Surface server-side validation errors when available so the
                // operator knows exactly which field is missing. Falls back to
                // a generic message if the body is unparseable.
                let message = `Test failed with HTTP ${response.status}.`;

                if (response.status === 422) {
                    try {
                        const body = (await response.json()) as {
                            errors?: Record<string, string[]>;
                            message?: string;
                        };

                        const fields = body.errors
                            ? Object.keys(body.errors)
                            : [];

                        message =
                            fields.length > 0
                                ? `Fill these fields before testing: ${fields.join(', ')}.`
                                : (body.message ??
                                  'Fill all connection fields before testing.');
                    } catch {
                        message = 'Fill all connection fields before testing.';
                    }
                }

                setTestResult({ ok: false, message });

                return;
            }

            const data = (await response.json()) as ConnectionTestResult;
            setTestResult(data);
        } catch (error) {
            setTestResult({
                ok: false,
                message:
                    error instanceof Error
                        ? error.message
                        : 'Connection probe failed unexpectedly.',
            });
        } finally {
            setTesting(false);
        }
    };

    const passwordPlaceholder = isEdit
        ? '(unchanged — type a new value to rotate)'
        : 'Database password';

    return (
        <form onSubmit={handleSubmit} className="space-y-6" noValidate>
            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Identity
                    </CardTitle>
                    <CardDescription>
                        Display name and the URL-safe slug used for path-prefix
                        tenant resolution. Domain is optional and used by
                        subdomain resolution when set.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                type="text"
                                maxLength={255}
                                value={form.data.name}
                                onChange={(e) => {
                                    const next = e.target.value;
                                    form.setData('name', next);

                                    if (!slugDirty) {
                                        form.setData('slug', toSlug(next));
                                    }
                                }}
                                placeholder="e.g. Acme School"
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="slug">Slug</Label>
                            <Input
                                id="slug"
                                type="text"
                                maxLength={255}
                                value={form.data.slug}
                                onChange={(e) => {
                                    form.setData('slug', e.target.value);
                                    setSlugDirty(true);
                                }}
                                placeholder="e.g. acme-school"
                                className="font-mono"
                                required
                            />
                            <p className="text-xs text-muted-foreground">
                                Lowercase letters, digits, and hyphens only.
                            </p>
                            <InputError message={form.errors.slug} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="domain">Domain (optional)</Label>
                        <Input
                            id="domain"
                            type="text"
                            maxLength={255}
                            value={form.data.domain}
                            onChange={(e) =>
                                form.setData('domain', e.target.value)
                            }
                            placeholder="e.g. acme.payroll.example.com"
                        />
                        <p className="text-xs text-muted-foreground">
                            When set, requests to this hostname resolve to this
                            tenant. Leave blank to use path-prefix resolution
                            only.
                        </p>
                        <InputError message={form.errors.domain} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        LMS connection
                    </CardTitle>
                    <CardDescription>
                        Read-only credentials Phase C uses to rebind the `lms`
                        connection per request. Use the Test connection button
                        after editing to confirm reachability.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="lms_db_host">Host</Label>
                            <Input
                                id="lms_db_host"
                                type="text"
                                maxLength={255}
                                value={form.data.lms_db_host}
                                onChange={(e) =>
                                    form.setData('lms_db_host', e.target.value)
                                }
                                placeholder="e.g. 127.0.0.1"
                                className="font-mono"
                                required
                            />
                            <InputError message={form.errors.lms_db_host} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="lms_db_port">Port</Label>
                            <Input
                                id="lms_db_port"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                value={String(form.data.lms_db_port)}
                                onChange={(e) => {
                                    const next = e.target.value;

                                    if (next === '') {
                                        form.setData('lms_db_port', '');

                                        return;
                                    }

                                    const parsed = Number(next);
                                    form.setData(
                                        'lms_db_port',
                                        Number.isNaN(parsed) ? next : parsed,
                                    );
                                }}
                                placeholder="3306"
                                className="text-right font-mono tabular-nums"
                                required
                            />
                            <InputError message={form.errors.lms_db_port} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="lms_db_database">Database</Label>
                            <Input
                                id="lms_db_database"
                                type="text"
                                maxLength={64}
                                value={form.data.lms_db_database}
                                onChange={(e) =>
                                    form.setData(
                                        'lms_db_database',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. lms_acme"
                                className="font-mono"
                                required
                            />
                            <InputError message={form.errors.lms_db_database} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="lms_db_username">Username</Label>
                            <Input
                                id="lms_db_username"
                                type="text"
                                maxLength={64}
                                value={form.data.lms_db_username}
                                onChange={(e) =>
                                    form.setData(
                                        'lms_db_username',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. lms_user"
                                className="font-mono"
                                required
                                autoComplete="off"
                            />
                            <InputError message={form.errors.lms_db_username} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="lms_db_password">Password</Label>
                            <PasswordInput
                                id="lms_db_password"
                                maxLength={255}
                                value={form.data.lms_db_password}
                                onChange={(e) =>
                                    form.setData(
                                        'lms_db_password',
                                        e.target.value,
                                    )
                                }
                                placeholder={passwordPlaceholder}
                                autoComplete="new-password"
                                required={!isEdit}
                            />
                            {isEdit ? (
                                <p className="text-xs text-muted-foreground">
                                    Leave blank to keep the current password.
                                </p>
                            ) : null}
                            <InputError message={form.errors.lms_db_password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="lms_db_charset">Charset</Label>
                            <Input
                                id="lms_db_charset"
                                type="text"
                                maxLength={32}
                                value={form.data.lms_db_charset}
                                onChange={(e) =>
                                    form.setData(
                                        'lms_db_charset',
                                        e.target.value,
                                    )
                                }
                                placeholder="utf8mb4"
                                className="font-mono"
                                required
                            />
                            <InputError message={form.errors.lms_db_charset} />
                        </div>
                    </div>

                    <div className="flex flex-col gap-3 rounded-md border bg-muted/30 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="space-y-0.5">
                            <p className="text-sm font-medium">
                                Test connection
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Runs SELECT 1 against the credentials above.
                                Nothing is saved.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={handleTestConnection}
                            disabled={testing}
                        >
                            {testing ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Plug className="mr-2 h-4 w-4" />
                            )}
                            {testing ? 'Testing…' : 'Test connection'}
                        </Button>
                    </div>

                    {testResult ? (
                        <div
                            role="status"
                            aria-live="polite"
                            className={cn(
                                'flex items-start gap-2 rounded-md border p-3 text-sm',
                                testResult.ok
                                    ? 'border-success/40 bg-success/10 text-success'
                                    : 'border-destructive/40 bg-destructive/10 text-destructive',
                            )}
                        >
                            {testResult.ok ? (
                                <CheckCircle2
                                    className="mt-0.5 h-4 w-4 flex-shrink-0"
                                    aria-hidden="true"
                                />
                            ) : (
                                <XCircle
                                    className="mt-0.5 h-4 w-4 flex-shrink-0"
                                    aria-hidden="true"
                                />
                            )}
                            <span className="break-words">
                                {testResult.message}
                            </span>
                        </div>
                    ) : null}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Status
                    </CardTitle>
                    <CardDescription>
                        Inactive schools stay in the registry but cannot be
                        resolved as the active tenant.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                        <div className="space-y-0.5">
                            <Label htmlFor="is_active">Active</Label>
                            <p className="text-xs text-muted-foreground">
                                When OFF, requests targeting this school are
                                rejected by the tenant resolver.
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
                </CardContent>
            </Card>

            <div className="flex items-center justify-end gap-2">
                <Button asChild variant="outline" type="button">
                    <Link href={schoolsIndex().url}>Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || (isEdit && !form.isDirty)}
                >
                    {form.processing ? (
                        <>
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            Saving…
                        </>
                    ) : isEdit ? (
                        'Save changes'
                    ) : (
                        'Create school'
                    )}
                </Button>
            </div>
        </form>
    );
}
