import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface StatCardProps {
    label: string;
    value: React.ReactNode;
    hint?: React.ReactNode;
    className?: string;
}

export function StatCard({ label, value, hint, className }: StatCardProps) {
    return (
        <Card className={cn('@container min-w-0', className)}>
            <CardHeader className="pb-2">
                <CardDescription>{label}</CardDescription>
                {/*
                  Sized by the card, not the viewport. A money figure never
                  wraps, so a tile that is narrower than its number overflows
                  the card edge; stepping the type down as the tile narrows
                  keeps the whole figure inside however the grid packs them.
                */}
                <CardTitle className="font-serif text-xl tabular-nums @min-[14rem]:text-2xl @min-[16rem]:text-3xl">
                    {value}
                </CardTitle>
            </CardHeader>
            {hint && (
                <CardContent className="text-xs text-muted-foreground">
                    {hint}
                </CardContent>
            )}
        </Card>
    );
}
