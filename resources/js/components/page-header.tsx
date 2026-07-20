import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface PageHeaderProps {
    eyebrow?: string;
    title: string;
    description?: string;
    actions?: React.ReactNode;
    /** When set, renders a "Back" button on the far left, before the title. */
    backHref?: string;
    /** Optional decorative icon shown in a soft accent circle beside the title. */
    icon?: LucideIcon;
    className?: string;
}

export function PageHeader({
    eyebrow,
    title,
    description,
    actions,
    backHref,
    icon: Icon,
    className,
}: PageHeaderProps) {
    const hasLeading = Boolean(backHref) || Boolean(Icon);

    return (
        <div
            className={cn(
                'flex flex-col gap-3 border-b pb-6 sm:flex-row sm:justify-between',
                // Center the row only when a back button / icon is present;
                // otherwise keep the original title-baseline alignment.
                hasLeading ? 'sm:items-center' : 'sm:items-end',
                className,
            )}
        >
            <div className="flex items-center gap-3 sm:gap-4">
                {backHref && (
                    <Button asChild variant="outline" size="sm">
                        <Link href={backHref}>
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                )}
                {Icon && (
                    <span className="hidden h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary sm:flex">
                        <Icon className="h-5 w-5" />
                    </span>
                )}
                <div className="space-y-1">
                    {eyebrow && (
                        <p className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                            {eyebrow}
                        </p>
                    )}
                    <h1 className="font-serif text-2xl font-semibold tracking-tight">
                        {title}
                    </h1>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
            </div>
            {actions && (
                <div className="flex flex-shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
