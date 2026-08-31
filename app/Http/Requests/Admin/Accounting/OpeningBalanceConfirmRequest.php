<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\JournalEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirm step: the one decision the preview cannot make for the user.
 *
 * `plug_to_retained_earnings` is separated from the upload rather than read
 * off the spreadsheet because it is a judgement about figures the user has
 * now seen, not a property of the file. Defaulting it false is what makes an
 * unbalanced snapshot fail loudly instead of quietly acquiring a plug nobody
 * asked for.
 */
final class OpeningBalanceConfirmRequest extends FormRequest
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
            'plug_to_retained_earnings' => ['nullable', 'boolean'],
        ];
    }

    public function shouldPlug(): bool
    {
        return $this->boolean('plug_to_retained_earnings');
    }
}
