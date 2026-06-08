<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Pas\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new row for `pas_schools`.
 *
 * Authorization is delegated to SchoolPolicy via `can('create', School::class)` —
 * super-admin only. The policy stays the single source of truth.
 *
 * `slug` is constrained to a URL-safe lowercase / digits / hyphen character
 * class so it can drive Phase C's path-prefix tenant finder without further
 * normalization.
 */
final class StoreSchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', School::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9-]+$/', 'max:255', Rule::unique('pas_schools', 'slug')],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('pas_schools', 'domain')],
            'lms_db_host' => ['required', 'string', 'max:255'],
            'lms_db_port' => ['required', 'integer', 'between:1,65535'],
            'lms_db_database' => ['required', 'string', 'max:64'],
            'lms_db_username' => ['required', 'string', 'max:64'],
            // `present|nullable|string` — the field must be in the payload
            // (catches client-side typos) but the value may be null OR an
            // empty string. Both shapes represent the same passwordless
            // MySQL state; `null` reaches us when the encrypted cast
            // roundtrips a NULL column from the DB, `""` when the operator
            // explicitly cleared the field.
            'lms_db_password' => ['present', 'nullable', 'string', 'max:255'],
            'lms_db_charset' => ['required', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
