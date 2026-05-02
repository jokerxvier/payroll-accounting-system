import { cn } from '@/lib/utils';

interface ThemeProbeProps {
    className?: string;
}

/**
 * Renders a single panel that exercises every design token defined in
 * `resources/css/app.css` and the three font families wired through the
 * `@theme inline` block. Mount on any page to verify the tokens render
 * correctly in light and dark mode. Not for production user surfaces.
 */
export function ThemeProbe({ className }: ThemeProbeProps) {
    return (
        <section
            aria-label="Theme probe"
            className={cn(
                'mx-auto mt-6 max-w-3xl space-y-4 rounded-lg border bg-card p-6 text-card-foreground shadow-sm',
                className,
            )}
        >
            <header className="space-y-1">
                <p className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                    Theme Probe
                </p>
                <h2 className="font-serif text-2xl font-semibold tracking-tight">
                    Cream, charcoal, and Book Cloth orange
                </h2>
                <p className="text-sm text-muted-foreground">
                    Smoke check for the design tokens defined in app.css.
                </p>
            </header>

            <div className="grid gap-3 text-sm sm:grid-cols-3">
                <p className="font-sans">
                    Inter (sans) renders body, navigation, table cells, and
                    forms across the application.
                </p>
                <p className="font-serif">
                    Source Serif 4 renders page titles, ledger headings, and
                    payslip mastheads.
                </p>
                <p className="font-mono text-xs">
                    JetBrains Mono renders codes (1100-001), IDs, and the
                    eyebrow label.
                </p>
            </div>

            <div className="flex flex-wrap gap-2 text-xs">
                <span className="rounded-md bg-primary px-2 py-1 font-medium text-primary-foreground">
                    primary
                </span>
                <span className="rounded-md bg-secondary px-2 py-1 font-medium text-secondary-foreground">
                    secondary
                </span>
                <span className="rounded-md bg-muted px-2 py-1 font-medium text-muted-foreground">
                    muted
                </span>
                <span className="rounded-md bg-accent px-2 py-1 font-medium text-accent-foreground">
                    accent
                </span>
                <span className="rounded-md bg-success/15 px-2 py-1 font-medium text-success">
                    success
                </span>
                <span className="rounded-md bg-warning/15 px-2 py-1 font-medium text-warning">
                    warning
                </span>
                <span className="rounded-md bg-destructive/15 px-2 py-1 font-medium text-destructive">
                    destructive
                </span>
                <span className="rounded-md bg-info/15 px-2 py-1 font-medium text-info">
                    info
                </span>
            </div>

            <p className="font-sans tabular-nums">
                Sample figures align as a column: 1,245,300.50 · 12,345.00 ·
                500.00
            </p>
        </section>
    );
}
