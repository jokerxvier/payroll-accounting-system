import { Head, useForm } from '@inertiajs/react';
import { Building2, ImageUp } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * A school's own identity — what appears on the documents it hands out.
 *
 * These four facts print on the invoice face and, now, the payslip header, and
 * until this screen existed they were settable only by seeder or tinker:
 * `/admin/schools` is platform-admin only, because those rows carry every
 * tenant's database credentials. A school could not correct its own letterhead.
 */

interface Props {
    organisation: {
        name: string | null;
        registered_name: string | null;
        tin: string | null;
        business_address: string | null;
        email: string | null;
        logo_url: string | null;
    };
}

export default function OrganisationIndex({ organisation }: Props) {
    const [preview, setPreview] = useState<string | null>(null);

    const form = useForm({
        registered_name: organisation.registered_name ?? '',
        tin: organisation.tin ?? '',
        business_address: organisation.business_address ?? '',
        email: organisation.email ?? '',
        logo: null as File | null,
        remove_logo: false as boolean,
    });

    const chooseLogo = (file: File | null) => {
        form.setData((previous) => ({
            ...previous,
            logo: file,
            // Choosing a file cancels a pending removal — otherwise ticking
            // remove and then picking a replacement would delete the upload.
            remove_logo: file ? false : previous.remove_logo,
        }));

        setPreview(file ? URL.createObjectURL(file) : null);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();

        // A file needs multipart, and Inertia cannot send multipart over
        // PATCH — so POST with a method spoof, which is what `_method` is for.
        form.transform((data) => ({ ...data, _method: 'patch' }));
        form.post('/admin/organisation', { forceFormData: true });
    };

    const shown =
        preview ?? (form.data.remove_logo ? null : organisation.logo_url);

    return (
        <>
            <Head title="Organisation" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Organisation"
                    description="How this school appears on the documents it issues — the invoice face, the payslip header, and the sidebar."
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Logo</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-4">
                                <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted/30">
                                    {shown ? (
                                        <img
                                            src={shown}
                                            alt="School logo"
                                            className="max-h-full max-w-full object-contain"
                                        />
                                    ) : (
                                        <Building2 className="size-7 text-muted-foreground" />
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="logo">
                                        <span className="sr-only">
                                            Choose a logo
                                        </span>
                                    </Label>
                                    <Input
                                        id="logo"
                                        type="file"
                                        accept="image/png,image/jpeg"
                                        aria-invalid={!!form.errors.logo}
                                        onChange={(e) =>
                                            chooseLogo(
                                                e.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        PNG or JPEG, under 1 MB. It is embedded
                                        in every invoice and payslip, so a
                                        smaller file keeps those documents
                                        light.
                                    </p>
                                    {form.errors.logo && (
                                        <p className="text-sm text-destructive">
                                            {form.errors.logo}
                                        </p>
                                    )}
                                </div>
                            </div>

                            {organisation.logo_url && !form.data.logo && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-muted-foreground"
                                    onClick={() => {
                                        form.setData('remove_logo', true);
                                        setPreview(null);
                                    }}
                                    disabled={form.data.remove_logo}
                                >
                                    <ImageUp className="mr-1 size-3.5 rotate-180" />
                                    {form.data.remove_logo
                                        ? 'Will be removed on save'
                                        : 'Remove logo'}
                                </Button>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Registered details
                                {organisation.name && (
                                    <span className="ml-2 font-normal text-muted-foreground">
                                        {organisation.name}
                                    </span>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="registered_name">
                                    Registered name
                                </Label>
                                <Input
                                    id="registered_name"
                                    value={form.data.registered_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'registered_name',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={organisation.name ?? ''}
                                    aria-invalid={!!form.errors.registered_name}
                                />
                                <p className="text-xs text-muted-foreground">
                                    The legal name on a BIR document, which
                                    often differs from the name you trade under.
                                </p>
                                {form.errors.registered_name && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.registered_name}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="email">Office email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    maxLength={160}
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                    placeholder="office@school.edu.ph"
                                    aria-invalid={!!form.errors.email}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Where a parent's reply goes when the school
                                    emails them an invoice. Leave it empty and
                                    replies reach nobody at the school.
                                </p>
                                {form.errors.email && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.email}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="tin">TIN</Label>
                                <Input
                                    id="tin"
                                    value={form.data.tin}
                                    onChange={(e) =>
                                        form.setData('tin', e.target.value)
                                    }
                                    aria-invalid={!!form.errors.tin}
                                />
                                {form.errors.tin && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.tin}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="business_address">
                                    Business address
                                </Label>
                                {/* There is no Textarea primitive in this
                                    codebase; the house style is a styled bare
                                    element, matching payment-form.tsx. */}
                                <textarea
                                    id="business_address"
                                    rows={3}
                                    value={form.data.business_address}
                                    onChange={(e) =>
                                        form.setData(
                                            'business_address',
                                            e.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        !!form.errors.business_address
                                    }
                                    className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive"
                                />
                                {form.errors.business_address && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.business_address}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Button type="submit" disabled={form.processing}>
                        Save
                    </Button>
                </form>
            </div>
        </>
    );
}

OrganisationIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/journal-entries' },
        { title: 'Organisation', href: '/admin/organisation' },
    ],
};
