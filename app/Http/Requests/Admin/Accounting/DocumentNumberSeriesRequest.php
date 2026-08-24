<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\DocumentNumberSeries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validation for a document numbering series.
 *
 * Authorization is the controller's job via `Gate::authorize`, so
 * `authorize()` returns true here.
 *
 * Two rules here protect the gapless guarantee rather than the data type:
 * the counter may not be moved backwards over numbers already issued, and
 * the authorised range may not be set to exclude the counter's current
 * position. Both would produce documents the Bureau has no record of
 * authorising.
 */
final class DocumentNumberSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();
        $existing = $this->routeSeries();

        return [
            'document_type' => [
                'required',
                Rule::in(DocumentNumberSeries::TYPES),
                // One series per document type per school: two counters for
                // the same type is how duplicate serials happen.
                Rule::unique('pas_document_number_series', 'document_type')
                    ->where(fn ($query) => $tenantId === null ? $query : $query->where('school_id', $tenantId))
                    ->ignore($existing?->getKey()),
            ],
            'label' => ['required', 'string', 'max:120'],
            'prefix' => ['nullable', 'string', 'max:16'],
            'padding' => ['required', 'integer', 'min:1', 'max:12'],
            'next_number' => ['required', 'integer', 'min:1'],
            'serial_start' => ['nullable', 'integer', 'min:1'],
            'serial_end' => ['nullable', 'integer', 'min:1', 'gte:serial_start'],
            'atp_number' => ['nullable', 'string', 'max:64'],
            'permit_issued_at' => ['nullable', 'date'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $existing = $this->routeSeries();
            $next = (int) $this->input('next_number');

            // Moving the counter back would re-issue serials that are
            // already on documents in someone's hands.
            if ($existing !== null && $next < $existing->next_number) {
                $v->errors()->add('next_number', sprintf(
                    'This series has already issued up to %s. Moving the counter back would duplicate serials that are already on issued documents.',
                    $existing->format($existing->next_number - 1),
                ));
            }

            $start = $this->input('serial_start');
            $end = $this->input('serial_end');

            // A range that excludes the counter means the very next document
            // would be refused, or worse, stamped outside what the Bureau
            // authorised.
            if ($start !== null && $next < (int) $start) {
                $v->errors()->add('next_number', 'The next number falls before the start of the authorised range.');
            }

            if ($end !== null && $next > (int) $end) {
                $v->errors()->add('serial_end', 'The authorised range ends before the next number this series would issue.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.unique' => 'This school already has a numbering series for that document type.',
            'serial_end.gte' => 'The end of the range cannot fall before its start.',
        ];
    }

    /** The series being edited, when this is an update. Null on create. */
    public function routeSeries(): ?DocumentNumberSeries
    {
        $series = $this->route('documentSeries');

        return $series instanceof DocumentNumberSeries ? $series : null;
    }
}
