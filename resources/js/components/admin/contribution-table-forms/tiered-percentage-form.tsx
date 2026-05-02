import { useId, useMemo } from 'react';
import {
    MoneyInput,
    PercentInput,
} from '@/components/admin/contribution-table-forms/shared';
import type { TieredPercentageRules } from '@/types';

/**
 * Subform for the Pag-IBIG-style `tiered_percentage` algorithm.
 *
 * Outputs:
 * <code>
 * {
 *   threshold,                       // centavos — split point between tiers
 *   lower: { ee_bp, er_bp },         // applied below threshold
 *   upper: { ee_bp, er_bp },         // applied at/above threshold
 *   max_msc                          // centavos — salary cap
 * }
 * </code>
 */
interface TieredPercentageFormProps {
    value: Record<string, unknown>;
    onChange: (next: Record<string, unknown>) => void;
    errors?: Record<string, string>;
}

function castTier(value: unknown): { ee_bp: number; er_bp: number } {
    if (typeof value !== 'object' || value === null) {
        return { ee_bp: 0, er_bp: 0 };
    }

    const tier = value as { ee_bp?: unknown; er_bp?: unknown };

    return {
        ee_bp: typeof tier.ee_bp === 'number' ? tier.ee_bp : 0,
        er_bp: typeof tier.er_bp === 'number' ? tier.er_bp : 0,
    };
}

function castRules(value: Record<string, unknown>): TieredPercentageRules {
    return {
        threshold: typeof value.threshold === 'number' ? value.threshold : 0,
        lower: castTier(value.lower),
        upper: castTier(value.upper),
        max_msc: typeof value.max_msc === 'number' ? value.max_msc : 0,
    };
}

export function TieredPercentageForm({
    value,
    onChange,
    errors,
}: TieredPercentageFormProps) {
    const rules = useMemo(() => castRules(value), [value]);

    const thresholdId = useId();
    const maxMscId = useId();
    const lowerEeId = useId();
    const lowerErId = useId();
    const upperEeId = useId();
    const upperErId = useId();

    const update = (patch: Partial<TieredPercentageRules>): void => {
        const next: TieredPercentageRules = { ...rules, ...patch };
        onChange(next as unknown as Record<string, unknown>);
    };

    const updateTier = (
        which: 'lower' | 'upper',
        patch: Partial<{ ee_bp: number; er_bp: number }>,
    ): void => {
        update({
            [which]: { ...rules[which], ...patch },
        } as Partial<TieredPercentageRules>);
    };

    const ruleError = errors?.rules;

    return (
        <div className="space-y-4">
            {ruleError && (
                <p className="text-sm text-destructive" role="alert">
                    {ruleError}
                </p>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <MoneyInput
                    id={thresholdId}
                    label="Threshold"
                    value={rules.threshold}
                    onChange={(threshold) => update({ threshold })}
                />
                <MoneyInput
                    id={maxMscId}
                    label="Max MSC"
                    value={rules.max_msc}
                    onChange={(max_msc) => update({ max_msc })}
                />
            </div>

            <div className="space-y-2">
                <p className="text-sm font-medium">
                    Lower tier (below threshold)
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <PercentInput
                        id={lowerEeId}
                        label="Employee"
                        value={rules.lower.ee_bp}
                        onChange={(ee_bp) => updateTier('lower', { ee_bp })}
                    />
                    <PercentInput
                        id={lowerErId}
                        label="Employer"
                        value={rules.lower.er_bp}
                        onChange={(er_bp) => updateTier('lower', { er_bp })}
                    />
                </div>
            </div>

            <div className="space-y-2">
                <p className="text-sm font-medium">
                    Upper tier (at or above threshold)
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <PercentInput
                        id={upperEeId}
                        label="Employee"
                        value={rules.upper.ee_bp}
                        onChange={(ee_bp) => updateTier('upper', { ee_bp })}
                    />
                    <PercentInput
                        id={upperErId}
                        label="Employer"
                        value={rules.upper.er_bp}
                        onChange={(er_bp) => updateTier('upper', { er_bp })}
                    />
                </div>
            </div>
        </div>
    );
}
