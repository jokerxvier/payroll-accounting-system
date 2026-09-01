<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\ChartOfAccount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload step of the chart import.
 *
 * Gated on `create`, the wider of the two abilities the file can exercise:
 * anyone allowed to add an account is allowed to correct one.
 */
final class ChartOfAccountImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ChartOfAccount::class) ?? false;
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
