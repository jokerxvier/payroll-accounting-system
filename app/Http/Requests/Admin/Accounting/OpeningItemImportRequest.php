<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\JournalEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload step of the open-items import.
 *
 * No cutover date, unlike {@see OpeningBalanceImportRequest}: these documents
 * hang off a cutover that already exists. Taking the date again would let an
 * operator state one that disagrees with `books_opened_on`, and the sub-ledger
 * would then be measured against a control balance from a different day.
 *
 * Authorised on `postOpeningBalance` rather than an ability of its own.
 * Recording the documents behind the opening receivable is the same act of
 * migration as stating the receivable, done by the same person in the same
 * sitting; a separate permission would be a second switch for one job.
 */
final class OpeningItemImportRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Upload the filled-in template as .xlsx, .xls or .csv.',
        ];
    }
}
