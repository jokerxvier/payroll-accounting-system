import { Link } from '@inertiajs/react';
import { ArrowLeft, Lock, SearchX, ServerCrash, Timer } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

export interface ErrorPageProps {
    status: number;
    title: string;
    description: string;
    icon: LucideIcon;
    /** Optional secondary message (e.g., a link to request access). */
    hint?: React.ReactNode;
    className?: string;
}

export function ErrorPage({
    status,
    title,
    description,
    icon: Icon,
    hint,
    className,
}: ErrorPageProps) {
    return (
        <div
            className={cn(
                'flex min-h-[60vh] flex-col items-center justify-center px-6 py-16 text-center',
                className,
            )}
        >
            <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                <Icon className="h-8 w-8" strokeWidth={1.5} />
            </div>

            <p className="mt-6 font-mono text-xs tracking-widest text-muted-foreground/80 uppercase">
                Error {status}
            </p>
            <h1 className="mt-2 max-w-xl font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
                {title}
            </h1>
            <p className="mt-3 max-w-lg text-sm text-muted-foreground sm:text-base">
                {description}
            </p>

            {hint && (
                <div className="mt-4 max-w-lg text-xs text-muted-foreground">
                    {hint}
                </div>
            )}

            <div className="mt-8 flex flex-wrap items-center justify-center gap-2">
                <Button
                    variant="outline"
                    onClick={() => window.history.back()}
                    type="button"
                >
                    <ArrowLeft className="mr-2 h-4 w-4" aria-hidden />
                    Go back
                </Button>
                <Button asChild>
                    <Link href={dashboard()}>Return to dashboard</Link>
                </Button>
            </div>
        </div>
    );
}

export const ErrorIcons = {
    Lock,
    SearchX,
    Timer,
    ServerCrash,
};
