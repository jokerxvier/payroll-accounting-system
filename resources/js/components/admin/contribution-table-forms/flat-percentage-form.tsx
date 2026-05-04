import { useId, useMemo } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * Subform for the `flat_percentage` algorithm — the simplest of the five
 * strategies. Designed for ad-hoc local taxes (e.g. a city tax) that don't
 * fit the SSS / PhilHealth / Pag-IBIG / BIR moulds.
 *
 * Unlike the four sibling forms (which speak basis points / centavos at the
 * boundary), this subform keeps the user-friendly decimal-percent /
 * decimal-pesos shape on the wire. The controller's
 * {@see App\Http\Requests\Admin\StatutoryContributionStoreRequest::prepareForValidation}
 * normalises the shape into the canonical *_bp form before persistence — so
 * the persisted JSON is always uniform regardless of which surface produced it.
 *
 * Output (the JSON the parent will POST as `rules`):
 * <code>
 * {
 *   rate_percent:           string,         // "1.5"
 *   employee_share_percent: string,         // "100"
 *   employer_share_percent: string,         // "0"
 *   cap_amount:             string | null,  // "50000.00" (pesos) or null
 * }
 * </code>
 *
 * Inline validation (rendered as helper text below the affected field):
 *   - rate_percent in (0, 100]
 *   - employee_share_percent + employer_share_percent === 100
 *   - cap_amount, when set, must be ≥ 0
 *
 * The other identifying fields (`contribution_code`, `label`, `effective_from`,
 * `applies_to`, `application_order`) are owned by the parent <create> page —
 * they are top-level columns on `pas_statutory_contributions`, not part of
 * the algorithm-specific `rules` JSON.
 *
 * Server-side validation (FormRequest + strategy) is the authoritative gate;
 * the inline checks here just give the user a tighter feedback loop.
 */
interface FlatPercentageFormProps {
    value: Record<string, unknown>;
    onChange: (next: Record<string, unknown>) => void;
    errors?: Record<string, string>;
}

interface FormShape {
    rate_percent: string;
    employee_share_percent: string;
    employer_share_percent: string;
    cap_amount: string;
}

function castString(value: unknown, fallback: string): string {
    if (typeof value === 'string') {
        return value;
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return fallback;
}

function castFormShape(value: Record<string, unknown>): FormShape {
    return {
        rate_percent: castString(value.rate_percent, ''),
        employee_share_percent: castString(value.employee_share_percent, '100'),
        employer_share_percent: castString(value.employer_share_percent, '0'),
        cap_amount:
            value.cap_amount === null ? '' : castString(value.cap_amount, ''),
    };
}

function parseNumeric(input: string): number | null {
    const cleaned = input.trim();

    if (cleaned === '') {
        return null;
    }

    const parsed = Number(cleaned);

    return Number.isFinite(parsed) ? parsed : null;
}

export function FlatPercentageForm({
    value,
    onChange,
    errors,
}: FlatPercentageFormProps) {
    const form = useMemo(() => castFormShape(value), [value]);

    const rateId = useId();
    const eeId = useId();
    const erId = useId();
    const capId = useId();

    const update = (patch: Partial<FormShape>): void => {
        // Strip cap_amount to null (not '') when the field is empty so the
        // controller's prepareForValidation receives a JSON null rather than
        // an empty string — bcmath would coerce '' to 0 and persist a 0 cap,
        // which is a meaningful (and wrong) value.
        const next: Record<string, unknown> = { ...form, ...patch };

        if (
            typeof next.cap_amount === 'string' &&
            next.cap_amount.trim() === ''
        ) {
            next.cap_amount = null;
        }

        onChange(next);
    };

    // ---- Derived validation ----
    const rateNumber = parseNumeric(form.rate_percent);
    const rateOk = rateNumber === null || (rateNumber > 0 && rateNumber <= 100);

    const eeNumber = parseNumeric(form.employee_share_percent);
    const erNumber = parseNumeric(form.employer_share_percent);
    const shareTotal = (eeNumber ?? 0) + (erNumber ?? 0);
    const shareOk = Math.abs(shareTotal - 100) < 0.0001;

    const capNumber = parseNumeric(form.cap_amount);
    const capOk = capNumber === null || capNumber >= 0;

    const ruleError = errors?.rules;

    const handleEmployeeShareChange = (next: string): void => {
        const parsed = parseNumeric(next);
        const employerDerived =
            parsed === null
                ? form.employer_share_percent
                : String(Math.max(0, 100 - parsed));

        update({
            employee_share_percent: next,
            employer_share_percent: employerDerived,
        });
    };

    return (
        <div className="space-y-4">
            {ruleError && (
                <p className="text-sm text-destructive" role="alert">
                    {ruleError}
                </p>
            )}

            <div className="grid gap-4 sm:grid-cols-3">
                <div className="grid gap-1.5">
                    <Label htmlFor={rateId}>Rate</Label>
                    <div className="relative">
                        <Input
                            id={rateId}
                            inputMode="decimal"
                            pattern="[0-9]*\.?[0-9]*"
                            value={form.rate_percent}
                            onChange={(e) =>
                                update({ rate_percent: e.target.value })
                            }
                            placeholder="1.50"
                            aria-invalid={!rateOk || undefined}
                            aria-describedby={`${rateId}-help`}
                            className="pr-7 text-right tabular-nums"
                        />
                        <span
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                        >
                            %
                        </span>
                    </div>
                    <p
                        id={`${rateId}-help`}
                        className={cn(
                            'text-xs',
                            rateOk
                                ? 'text-muted-foreground'
                                : 'text-destructive',
                        )}
                        role={rateOk ? undefined : 'alert'}
                    >
                        {rateOk
                            ? 'Total rate applied to the chosen basis.'
                            : 'Rate must be greater than 0% and at most 100%.'}
                    </p>
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor={eeId}>Employee share</Label>
                    <div className="relative">
                        <Input
                            id={eeId}
                            inputMode="decimal"
                            pattern="[0-9]*\.?[0-9]*"
                            value={form.employee_share_percent}
                            onChange={(e) =>
                                handleEmployeeShareChange(e.target.value)
                            }
                            placeholder="100"
                            aria-invalid={!shareOk || undefined}
                            aria-describedby={`${eeId}-help`}
                            className="pr-7 text-right tabular-nums"
                        />
                        <span
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                        >
                            %
                        </span>
                    </div>
                    <p
                        id={`${eeId}-help`}
                        className="text-xs text-muted-foreground"
                    >
                        Employee portion of the total.
                    </p>
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor={erId}>Employer share</Label>
                    <div className="relative">
                        <Input
                            id={erId}
                            inputMode="decimal"
                            pattern="[0-9]*\.?[0-9]*"
                            value={form.employer_share_percent}
                            onChange={(e) =>
                                update({
                                    employer_share_percent: e.target.value,
                                })
                            }
                            placeholder="0"
                            aria-invalid={!shareOk || undefined}
                            aria-describedby={`${erId}-help`}
                            className="pr-7 text-right tabular-nums"
                        />
                        <span
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                        >
                            %
                        </span>
                    </div>
                    <p
                        id={`${erId}-help`}
                        className={cn(
                            'text-xs',
                            shareOk
                                ? 'text-muted-foreground'
                                : 'text-destructive',
                        )}
                        role={shareOk ? undefined : 'alert'}
                    >
                        {shareOk
                            ? 'Auto-derived from the employee share. Override if needed.'
                            : `Employee + Employer must total 100%. Currently: ${shareTotal.toFixed(2)}%.`}
                    </p>
                </div>
            </div>

            <div className="grid gap-1.5 sm:max-w-sm">
                <Label htmlFor={capId}>Cap amount</Label>
                <div className="relative">
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                    >
                        ₱
                    </span>
                    <Input
                        id={capId}
                        inputMode="decimal"
                        pattern="[0-9]*\.?[0-9]*"
                        value={form.cap_amount}
                        onChange={(e) => update({ cap_amount: e.target.value })}
                        placeholder="50000.00"
                        aria-invalid={!capOk || undefined}
                        aria-describedby={`${capId}-help`}
                        className="pl-7 text-right tabular-nums"
                    />
                </div>
                <p
                    id={`${capId}-help`}
                    className={cn(
                        'text-xs',
                        capOk ? 'text-muted-foreground' : 'text-destructive',
                    )}
                    role={capOk ? undefined : 'alert'}
                >
                    {capOk
                        ? 'Optional. Maximum amount the rate is applied to per period. Leave blank for no cap.'
                        : 'Cap must be greater than or equal to 0.'}
                </p>
            </div>
        </div>
    );
}
