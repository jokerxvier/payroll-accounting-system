import { cn } from '@/lib/utils';

interface PageHeaderProps {
    eyebrow?: string;
    title: string;
    description?: string;
    actions?: React.ReactNode;
    className?: string;
}

export function PageHeader({
    eyebrow,
    title,
    description,
    actions,
    className,
}: PageHeaderProps) {
    return (
        <div
            className={cn(
                'flex flex-col gap-3 border-b pb-6 sm:flex-row sm:items-end sm:justify-between',
                className,
            )}
        >
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
            {actions && (
                <div className="flex flex-shrink-0 items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
