<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\JournalEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload step of the cutover import: the worksheet, and the date it speaks
 * for.
 *
 * The cutover date is deliberately unbounded in both directions, matching
 * {@see JournalEntryRequest}. A school migrating mid-year opens its books on
 * a date in the recent past; one preparing ahead of a switch-over opens them
 * on a date in the near future. Neither is wrong, and the only judgement
 * that matters — is there an open accounting period covering it — belongs to
 * `AccountingPeriodGuard`, which the preview consults and the posting action
 * enforces.
 */
final class OpeningBalanceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('postOpeningBalance', JournalEntry::class) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'cutover_date' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cutover_date.required' => 'Give the date these balances are stated as at.',
            'file.mimes' => 'Upload the filled-in template as .xlsx, .xls or .csv.',
        ];
    }
}
