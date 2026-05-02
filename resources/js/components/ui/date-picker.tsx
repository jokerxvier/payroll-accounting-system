import { format, parseISO } from 'date-fns';
import { CalendarIcon, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

interface DatePickerProps {
    id?: string;
    /** 'YYYY-MM-DD' or '' when empty (matches Inertia useForm string fields). */
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    ariaInvalid?: boolean;
}

function parseValue(value: string): Date | undefined {
    if (value === '') {
        return undefined;
    }

    try {
        const parsed = parseISO(value);
        return Number.isNaN(parsed.getTime()) ? undefined : parsed;
    } catch {
        return undefined;
    }
}

export function DatePicker({
    id,
    value,
    onChange,
    placeholder = 'Pick a date',
    disabled = false,
    className,
    ariaInvalid,
}: DatePickerProps) {
    const selected = parseValue(value);

    const handleSelect = (date: Date | undefined) => {
        onChange(date ? format(date, 'yyyy-MM-dd') : '');
    };

    const handleClear = () => {
        onChange('');
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    aria-invalid={ariaInvalid || undefined}
                    className={cn(
                        'w-full justify-start text-left font-normal',
                        !selected && 'text-muted-foreground',
                        className,
                    )}
                >
                    <CalendarIcon className="mr-2 h-4 w-4" />
                    {selected ? format(selected, 'MMM d, yyyy') : placeholder}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start" sideOffset={4}>
                <Calendar
                    mode="single"
                    selected={selected}
                    onSelect={handleSelect}
                    autoFocus
                />
                {selected && (
                    <div className="flex justify-end border-t px-3 py-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={handleClear}
                        >
                            <X className="mr-1 h-3.5 w-3.5" />
                            Clear
                        </Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
