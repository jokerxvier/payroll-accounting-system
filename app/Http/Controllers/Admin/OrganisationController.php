<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrganisationRequest;
use App\Models\Pas\School;
use App\Services\SchoolLogo;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Multitenancy\Models\Tenant;

/**
 * A school's own identity — what appears on the documents it hands out.
 *
 * Deliberately NOT part of `/admin/schools`, which is platform-admin only
 * because those rows carry every tenant's LMS database credentials. A school
 * correcting its own registered name should not need a cross-tenant
 * administrator, so this screen is gated on the school-scoped role instead —
 * the same shape as the payment-gateway settings beside it.
 *
 * It always edits the CURRENT tenant. There is no school id in the route, so
 * there is nothing to tamper with: whichever school the request resolved to is
 * the one being edited.
 */
final class OrganisationController extends Controller
{
    /**
     * Who may edit the school's own identity.
     *
     * `platform-admin` is listed explicitly. A direct `hasAnyRole()` check
     * bypasses the `Gate::before` short-circuit that normally grants it
     * everything, so omitting it here would lock the cross-tenant operator out
     * of a screen they can reach everywhere else — the same reason
     * `AuditLogController::AUDIT_ROLES` names it.
     *
     * Otherwise this is `AccountingRoles::PAYMENT_GATEWAY`: the letterhead and
     * the merchant credentials are the same trust level, both being things a
     * school presents to the outside world.
     *
     * @var list<string>
     */
    private const ORGANISATION_ROLES = ['platform-admin', 'super-admin'];

    public function __construct(
        private readonly SchoolLogo $logos,
    ) {}

    public function edit(): Response
    {
        $this->authorizeAccess();

        $school = $this->currentSchool();

        return Inertia::render('admin/organisation/index', [
            'organisation' => [
                'name' => $school?->name,
                'registered_name' => $school?->registered_name,
                'tin' => $school?->tin,
                'business_address' => $school?->business_address,
                'email' => $school?->email,
                'logo_url' => $this->logos->url($school),
            ],
        ]);
    }

    public function update(OrganisationRequest $request): RedirectResponse
    {
        $this->authorizeAccess();

        $school = $this->currentSchool();

        abort_if($school === null, 404);

        $data = $request->validated();

        $school->fill([
            'registered_name' => $data['registered_name'] ?? null,
            'tin' => $data['tin'] ?? null,
            'business_address' => $data['business_address'] ?? null,
            'email' => $data['email'] ?? null,
        ]);

        // Order matters: a new file replaces whatever is there, and a removal
        // only applies when no replacement was sent — otherwise ticking remove
        // and choosing a file at once would delete the one just uploaded.
        if ($request->hasFile('logo')) {
            $school->logo_path = $this->logos->store($school, $request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            $this->logos->clear($school);
            $school->logo_path = null;
        }

        $school->save();

        return back()->with('success', 'Organisation details saved.');
    }

    private function authorizeAccess(): void
    {
        abort_unless(
            request()->user()?->hasAnyRole(self::ORGANISATION_ROLES) ?? false,
            403,
        );
    }

    /**
     * `Tenant::current()` is typed as Spatie's base model; the columns are ours.
     */
    private function currentSchool(): ?School
    {
        $tenant = Tenant::current();

        return $tenant instanceof School ? $tenant : null;
    }
}
