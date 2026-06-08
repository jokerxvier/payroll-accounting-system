<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Fortify authentication model.
 *
 * Phase A.2 of the multi-tenant refactor moved this model from the LMS-owned
 * `users` table onto `pas_users`, which lives on the payroll-DB-owned default
 * `mysql` connection. Identity (name, email, role) is still mastered in each
 * tenant's LMS — the `lms_user_id` column on `pas_users` cross-references the
 * canonical LMS row, and `App\Models\Lms\User` (a ReadOnlyModel pinned to the
 * `lms` connection) is the only legal read path back to LMS identity data.
 *
 * Authentication entry point: `FortifyServiceProvider::configureAuthentication()`
 * registers a `Fortify::authenticateUsing` callback that:
 *   1. Looks up the LMS user by email.
 *   2. Verifies the password against `lms.users.password` (LMS is identity master).
 *   3. Upserts a row in `pas_users` (id-preserved from LMS) via
 *      `App\Services\Auth\UpsertPasUserFromLms`, mirroring the LMS password
 *      hash so subsequent Fortify operations (session continuity, password
 *      confirmation, 2FA) work against `pas_users.password` without
 *      re-querying LMS on every request.
 *
 * Fortify writes (password mirror at login, `email_verified_at` on
 * verification, two_factor_* on enrollment) target `pas_users` directly.
 * The LMS-write enforcement that lived here in Phase 1 (`$lmsWritableColumns`
 * + `save()` allowlist + `LmsWriteException`) was removed in Phase A.2/A.4
 * because `pas_users` is fully app-writable. The read-only contract on the
 * LMS side is still enforced exhaustively by `App\Models\Lms\ReadOnlyModel`.
 *
 * id-preservation invariant: `pas_users.id == users.id` (LMS) for every
 * user originating in the LMS. The five FKs from `pas_*` tables to
 * `users(id)` (audit logs + payroll-run lifecycle columns + statutory
 * void-by) will be redirected to `pas_users(id)` in Phase A.3 with zero
 * data migration because of this invariant.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    protected $table = 'pas_users';

    protected $guard_name = 'web';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The payroll profile that this user owns, if any.
     *
     * Resolves the chain `pas_users.id → sm_staffs.user_id → pas_employee_profiles.lms_staff_id`.
     * Works because the Phase A.1 backfill preserved ids — `pas_users.id`
     * equals the original LMS `users.id`, which is the value referenced by
     * `sm_staffs.user_id`. There is no direct FK from `pas_users` to
     * `pas_employee_profiles`, so we look the staff record up first.
     *
     * Returns null when the user has no LMS staff record or no payroll
     * profile has been provisioned for that staff record yet.
     *
     * Used by the per-employee `App\Policies\Pas\*` policies to grant the
     * "view your own" carve-out (`$model->employee_profile_id === $user->employeeProfile?->id`).
     *
     * Implemented as a memoized accessor rather than an Eloquent relation
     * because the join hops across the `lms` connection (Staff is read-only
     * on that connection) — Eloquent's BelongsTo issues a single-connection
     * query and would break under a future physical split of the two DBs.
     */
    public function getEmployeeProfileAttribute(): ?EmployeeProfile
    {
        if ($this->memoizedEmployeeProfileResolved) {
            return $this->memoizedEmployeeProfile;
        }

        $this->memoizedEmployeeProfileResolved = true;

        $staffId = Staff::query()->where('user_id', $this->id)->value('id');

        if ($staffId === null) {
            return $this->memoizedEmployeeProfile = null;
        }

        return $this->memoizedEmployeeProfile = EmployeeProfile::query()
            ->where('lms_staff_id', $staffId)
            ->first();
    }

    private ?EmployeeProfile $memoizedEmployeeProfile = null;

    private bool $memoizedEmployeeProfileResolved = false;
}
