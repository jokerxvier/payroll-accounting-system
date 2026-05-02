import { useId, useMemo } from 'react';
import {
    MoneyInput,
    PercentInput,
} from '@/components/admin/contribution-table-forms/shared';
import type { PercentageWithCapRules } from '@/types';

/**
 * Subform for the PhilHealth-style `percentage_with_cap` algorithm.
 *
 * Outputs:
 * <code>
 * { rate_bp, floor, ceiling, split: { employee_bp, employer_bp } }
 * </code>
 *
 * The split must sum to `10000` basis points (100%); the strategy validates
 * that on submit, so the UI just shows a hint.
 */
interface PercentageWithCapFormProps {
    value: Record<string, unknown>;
    onChange: (next: Record<string, unknown>) => void;
    errors?: Record<string, string>;
}

function castRules(value: Record<string, unknown>): PercentageWithCapRules {
    const split =
        typeof value.split === 'object' && value.split !== null
            ? (value.split as { employee_bp?: unknown; employer_bp?: unknown })
            : {};

    return {
        rate_bp: typeof value.rate_bp === 'number' ? value.rate_bp : 0,
        floor: typeof value.floor === 'number' ? value.floor : 0,
        ceiling: typeof value.ceiling === 'number' ? value.ceiling : 0,
        split: {
            employee_bp:
                typeof split.employee_bp === 'number' ? split.employee_bp : 0,
            employer_bp:
                typeof split.employer_bp === 'number' ? split.employer_bp : 0,
        },
    };
}

export function PercentageWithCapForm({
    value,
    onChange,
    errors,
}: PercentageWithCapFormProps) {
    const rules = useMemo(() => castRules(value), [value]);

    const rateId = useId();
    const floorId = useId();
    const ceilingId = useId();
    const eeId = useId();
    const erId = useId();

    const update = (patch: Partial<PercentageWithCapRules>): void => {
        const next: PercentageWithCapRules = { ...rules, ...patch };
        onChange(next as unknown as Record<string, unknown>);
    };

    const updateSplit = (
        patch: Partial<PercentageWithCapRules['split']>,
    ): void => {
        update({ split: { ...rules.split, ...patch } });
    };

    const splitTotalBp = rules.split.employee_bp + rules.split.employer_bp;
    const splitOk = splitTotalBp === 10_000;
    const ruleError = errors?.rules;

    return (
        <div className="space-y-4">
            {ruleError && (
                <p className="text-sm text-destructive" role="alert">
                    {ruleError}
                </p>
            )}

            <PercentInput
                id={rateId}
                label="Premium rate"
                value={rules.rate_bp}
                onChange={(rate_bp) => update({ rate_bp })}
            />

            <div className="grid gap-4 sm:grid-cols-2">
                <MoneyInput
                    id={floorId}
                    label="Floor"
                    value={rules.floor}
                    onChange={(floor) => update({ floor })}
                />
                <MoneyInput
                    id={ceilingId}
                    label="Ceiling"
                    value={rules.ceiling}
                    onChange={(ceiling) => update({ ceiling })}
                />
            </div>

            <div className="space-y-2">
                <p className="text-sm font-medium">Split</p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <PercentInput
                        id={eeId}
                        label="Employee"
                        value={rules.split.employee_bp}
                        onChange={(employee_bp) => updateSplit({ employee_bp })}
                    />
                    <PercentInput
                        id={erId}
                        label="Employer"
                        value={rules.split.employer_bp}
                        onChange={(employer_bp) => updateSplit({ employer_bp })}
                    />
                </div>
                <p
                    className={
                        splitOk
                            ? 'text-xs text-muted-foreground'
                            : 'text-xs text-warning'
                    }
                    role={splitOk ? undefined : 'alert'}
                >
                    Employee + Employer must total 100%. Currently:{' '}
                    {(splitTotalBp / 100).toFixed(2)}%.
                </p>
            </div>
        </div>
    );
}
