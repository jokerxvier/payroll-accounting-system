<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\Contact;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload step of the contact import.
 *
 * Authorised on `create` rather than `update`: the file may do either, and a
 * sheet that can raise a contact is the wider of the two abilities. Anyone
 * allowed to create one is allowed to correct one.
 */
final class ContactImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Contact::class) ?? false;
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
