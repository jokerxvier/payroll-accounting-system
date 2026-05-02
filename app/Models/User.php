<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\LmsWriteException;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Fortify authentication model.
 *
 * In production this maps onto the LMS-owned `users` table (shared physical
 * DB, default `mysql` connection). The LMS owns identity (name, email, role,
 * department) — this app must NEVER write to those columns. Only
 * `password` and `remember_token` are app-writable, exclusively for Fortify's
 * password reset flow. All other LMS-owned columns are blocked at the model
 * layer via the $lmsWritableColumns allowlist.
 *
 * Defense-in-depth note: the read-only LMS query model lives at
 * `App\Models\Lms\User` and extends `ReadOnlyModel`. Any code path that
 * needs to read identity (e.g. EmployeeRepository) MUST use that model so
 * the LMS boundary is preserved even if this auth model is mis-used.
 *
 * Connection note: this model intentionally does NOT pin to the `lms`
 * connection. The `lms` connection is reserved for strictly read-only
 * traffic against the LMS schema (enforced by ReadOnlyModel). Fortify's
 * password reset writes a single column on this table, so it must travel
 * over the writable default connection. Both connections currently point at
 * the same physical DB (payroll_db); a future physical split is a
 * connection-string change with no app-code impact.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    protected $table = 'users';

    protected $guard_name = 'web';

    /**
     * Columns this app may write to on the shared LMS users table.
     *
     * Anything outside this allowlist that ends up in the dirty set on save()
     * raises an LmsWriteException. This is the runtime enforcement of the
     * "LMS identity is read-only" contract for the auth model.
     */
    protected array $lmsWritableColumns = [
        'password',
        'remember_token',
        // Email verification — Fortify writes this when the user verifies their
        // address. The LMS does not own the verification timestamp.
        'email_verified_at',
    ];

    protected $fillable = [
        'full_name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['name'];

    /**
     * Surface the LMS-owned `full_name` column as `name` so Inertia auth payloads
     * and shadcn user widgets work without fanning the rename through the UI.
     */
    public function getNameAttribute(): ?string
    {
        return $this->attributes['full_name'] ?? null;
    }

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

    public function save(array $options = []): bool
    {
        // New rows are always allowed (creation populates many columns at once);
        // it's only updates after the row exists that must be column-restricted,
        // because that's when the app could trample LMS-owned identity.
        if ($this->exists) {
            $forbidden = array_diff(array_keys($this->getDirty()), $this->lmsWritableColumns);

            if ($forbidden !== []) {
                throw new LmsWriteException(
                    static::class,
                    sprintf('update LMS-owned columns [%s] on', implode(', ', $forbidden)),
                );
            }
        }

        return parent::save($options);
    }
}
