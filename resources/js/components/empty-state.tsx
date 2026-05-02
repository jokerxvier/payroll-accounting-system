import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

interface EmptyStateProps {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: React.ReactNode;
    className?: string;
}

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed bg-card/50 px-6 py-16 text-center',
                className,
            )}
        >
            {Icon && (
                <Icon
                    className="h-10 w-10 text-muted-foreground/60"
                    strokeWidth={1.5}
                />
            )}
            <div className="space-y-1">
                <h3 className="font-serif text-lg font-medium">{title}</h3>
                {description && (
                    <p className="max-w-md text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
