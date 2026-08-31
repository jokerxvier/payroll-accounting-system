import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Label } from '@/components/ui/label';
import type { DashboardPreset } from '@/types';

/**
 * The range control both financial dashboards share.
 *
 * Two questions that look like one and are not, which is what this component
 * exists to keep straight:
 *
 *  - **Which preset is selected** is a client-side choice, live the moment it
 *    is clicked. Custom has to unlock the pickers before any round trip,
 *    because the round trip is what it is unlocking them to compose. Reading
 *    this from the server's response is what made Custom do nothing at all.
 *  - **Which dates are shown** belongs to the server while a preset is active:
 *    "this year" is whatever the school's accounting periods say, and a
 *    calendar guess would be wrong for any school whose year does not start in
 *    January.
 *
 * The pickers stay visible, disabled, on a preset rather than being hidden —
 * "This year" means nothing until you can see that it resolved to July.
 */
export function ReportRangeFilter({
    preset,
    from,
    to,
    processing,
    onPreset,
    onFrom,
    onTo,
    onApply,
}: {
    preset: DashboardPreset;
    from: string;
    to: string;
    processing: boolean;
    onPreset: (preset: DashboardPreset) => void;
    onFrom: (value: string) => void;
    onTo: (value: string) => void;
    onApply: () => void;
}) {
    const presets: Array<{ value: DashboardPreset; label: string }> = [
        { value: 'month', label: 'This month' },
        { value: 'quarter', label: 'This quarter' },
        { value: 'year', label: 'This year' },
        { value: 'custom', label: 'Custom' },
    ];

    return (
        <Card>
            <CardContent className="flex flex-wrap items-end gap-3 pt-6">
                <div className="flex flex-wrap gap-2">
                    {presets.map((option) => (
                        <Button
                            key={option.value}
                            type="button"
                            size="sm"
                            variant={
                                preset === option.value ? 'default' : 'outline'
                            }
                            disabled={processing}
                            onClick={() => onPreset(option.value)}
                        >
                            {option.label}
                        </Button>
                    ))}
                </div>

                {/*
                  The pickers stay visible on a preset so the range it resolved
                  to is readable — "This year" means nothing until you can see
                  that this school's year starts in June.
                */}
                <div className="w-[11rem] space-y-1">
                    <Label htmlFor="from">From</Label>
                    <DatePicker
                        id="from"
                        value={from}
                        onChange={onFrom}
                        disabled={preset !== 'custom'}
                    />
                </div>
                <div className="w-[11rem] space-y-1">
                    <Label htmlFor="to">To</Label>
                    <DatePicker
                        id="to"
                        value={to}
                        onChange={onTo}
                        disabled={preset !== 'custom'}
                    />
                </div>

                {preset === 'custom' ? (
                    <Button
                        type="button"
                        disabled={processing}
                        onClick={onApply}
                    >
                        {processing ? 'Loading…' : 'Apply'}
                    </Button>
                ) : null}
            </CardContent>
        </Card>
    );
}
