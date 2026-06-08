<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Pas\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an update to an existing `pas_schools` row.
 *
 * Differences from StoreSchoolRequest:
 *   - `slug` and `domain` unique rules ignore the row being edited so
 *     a no-op edit isn't blocked by its own value.
 *   - `lms_db_password` is nullable; when empty / missing, the controller
 *     does NOT update the column so existing credentials survive an edit
 *     where the operator only changed unrelated fields. This is the
 *     standard "leave password blank to keep current" pattern.
 *
 * Authorization delegates to SchoolPolicy::update — super-admin only.
 */
final class UpdateSchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        $school = $this->route('school');

        return $school instanceof School
            && ($this->user()?->can('update', $school) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $schoolId = $this->route('school') instanceof School
            ? $this->route('school')->getKey()
            : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'regex:/^[a-z0-9-]+$/',
                'max:255',
                Rule::unique('pas_schools', 'slug')->ignore($schoolId),
            ],
            'domain' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('pas_schools', 'domain')->ignore($schoolId),
            ],
            'lms_db_host' => ['required', 'string', 'max:255'],
            'lms_db_port' => ['required', 'integer', 'between:1,65535'],
            'lms_db_database' => ['required', 'string', 'max:64'],
            'lms_db_username' => ['required', 'string', 'max:64'],
            'lms_db_password' => ['nullable', 'string', 'max:255'],
            'lms_db_charset' => ['required', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
