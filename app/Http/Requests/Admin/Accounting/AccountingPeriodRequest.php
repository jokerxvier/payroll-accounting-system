<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\AccountingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validates both creation and editing of a `pas_accounting_periods` row.
 *
 * One class for both operations: the rules are identical apart from
 * `ignore()` on the unique rule and the overlap check, both of which handle
 * the null (create) case already.
 *
 * The load-bearing rule here is **non-overlap**. `AccountingPeriod::covering()`
 * assumes at most one period can contain a given date, and Slice 2's posting
 * guard resolves an entry's period by looking that date up. If two periods
 * overlapped, the period a journal entry got filed under would depend on row
 * order — and closing one of them would only half-freeze the ledger. The
 * database cannot express "no overlapping ranges" as a constraint, so it is
 * enforced here.
 *
 * Status is not accepted from the client: open → closed → open happens
 * through the dedicated close/reopen endpoints, which carry the actor stamps
 * and the narrower CLOSE_PERIOD authorization.
 */
final class AccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Derive `fiscal_year` from the start date when the client omits it.
     * A period is overwhelmingly filed under the year it opens in; letting
     * the operator override covers non-calendar fiscal years.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('start_date') && ! $this->filled('fiscal_year')) {
            $start = $this->parseDate((string) $this->input('start_date'));

            if ($start !== null) {
                $this->merge(['fiscal_year' => (int) $start->format('Y')]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();
        $period = $this->routePeriod();

        $codeUniqueRule = Rule::unique('pas_accounting_periods', 'code')
            ->ignore($period?->getKey());
        if ($tenantId !== null) {
            $codeUniqueRule = $codeUniqueRule->where('school_id', $tenantId);
        }

        return [
            'code' => ['required', 'string', 'max:32', $codeUniqueRule],
            'name' => ['nullable', 'string', 'max:120'],
            'start_date' => ['required', 'date'],
            // `after_or_equal` rather than `after`: a one-day adjustment
            // period is unusual but legitimate.
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'fiscal_year' => ['required', 'integer', 'min:1900', 'max:2200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Only worth checking once the dates themselves are valid;
            // otherwise we would report a confusing overlap error on top of
            // a plain format error.
            if ($v->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $start = $this->parseDate((string) $this->input('start_date'));
            $end = $this->parseDate((string) $this->input('end_date'));

            if ($start === null || $end === null) {
                return;
            }

            $overlapping = AccountingPeriod::query()
                ->when(
                    $this->routePeriod() !== null,
                    fn ($query) => $query->whereKeyNot($this->routePeriod()?->getKey()),
                )
                // Two ranges overlap iff each starts on or before the other
                // ends. Cheaper and less error-prone than enumerating the
                // four containment cases.
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->first();

            if ($overlapping !== null) {
                $v->errors()->add(
                    'start_date',
                    "This range overlaps the existing period '{$overlapping->code}' ({$overlapping->start_date->toDateString()} to {$overlapping->end_date->toDateString()}). Accounting periods may not overlap."
                );
            }
        });
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function routePeriod(): ?AccountingPeriod
    {
        $period = $this->route('accountingPeriod');

        return $period instanceof AccountingPeriod ? $period : null;
    }
}
