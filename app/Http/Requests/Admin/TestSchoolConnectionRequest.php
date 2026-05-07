<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Pas\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload submitted to the `/admin/schools/test-connection`
 * endpoint.
 *
 * Identity columns (slug, domain, is_active) are intentionally absent —
 * this request only carries enough fields to attempt a `SELECT 1` against
 * the proposed LMS database. Nothing is persisted. Reusing the `create`
 * ability for authorization is fine because both the test button and the
 * create form gate on super-admin via SchoolPolicy.
 */
final class TestSchoolConnectionRequest extends FormRequest
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
            'lms_db_host' => ['required', 'string', 'max:255'],
            'lms_db_port' => ['required', 'integer', 'between:1,65535'],
            'lms_db_database' => ['required', 'string', 'max:64'],
            'lms_db_username' => ['required', 'string', 'max:64'],
            // `present|nullable|string` — see StoreSchoolRequest for full
            // explanation. The field must be in the payload but may be null
            // OR an empty string; both represent passwordless MySQL.
            'lms_db_password' => ['present', 'nullable', 'string', 'max:255'],
            'lms_db_charset' => ['required', 'string', 'max:32'],
        ];
    }
}
