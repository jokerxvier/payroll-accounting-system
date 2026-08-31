<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\SchoolLogo;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A school editing its own letterhead.
 *
 * The three identity fields here are printed on the invoice face and the
 * public payment page, and until now were settable only by seeder or tinker —
 * they appear in neither school request nor the school form, and
 * `/admin/schools` is platform-admin only by design because those rows hold
 * other tenants' database credentials. So a school could not correct its own
 * registered name.
 *
 * **PNG and JPEG only. Never SVG.** An SVG is a script-bearing document, and
 * accepting one from a form and serving it back is stored XSS — the fact that
 * it would also render poorly in dompdf is the lesser reason. `image` is kept
 * alongside `mimes` deliberately: `mimes` inspects the real mime type, and
 * `image` refuses anything that is not a decodable image, so a renamed
 * executable fails both.
 */
final class OrganisationRequest extends FormRequest
{
    /**
     * The controller does the gating; this mirrors it so a request cannot be
     * validated by someone the controller would refuse.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['platform-admin', 'super-admin']) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'registered_name' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:32'],
            'business_address' => ['nullable', 'string', 'max:2000'],
            // Where a parent's reply lands. Not a login and not unique: two
            // schools on one platform may legitimately share an office inbox.
            'email' => ['nullable', 'email', 'max:160'],
            // Absent means "keep the current logo" — the same rule the gateway
            // screen uses for a stored secret, and for the same reason: the
            // form cannot resend a file it was never given.
            'logo' => [
                'nullable',
                'file',
                'image',
                'mimes:png,jpg,jpeg',
                'max:'.SchoolLogo::MAX_KILOBYTES,
                'dimensions:min_width=32,min_height=32,max_width=2000,max_height=2000',
            ],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.mimes' => 'Upload a PNG or JPEG. SVG files are not accepted.',
            'logo.image' => 'That file is not an image the system can read.',
            'logo.max' => 'Keep the logo under 1 MB — it is embedded in every invoice and payslip.',
            'logo.dimensions' => 'The logo should be between 32 and 2000 pixels on each side.',
        ];
    }
}
