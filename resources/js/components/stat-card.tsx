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
        <Card className={cn(className)}>
            <CardHeader className="pb-2">
                <CardDescription>{label}</CardDescription>
                <CardTitle className="font-serif text-3xl tabular-nums">
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
