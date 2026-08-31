<?php

declare(strict_types=1);

namespace App\Models\Lms;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student in the LMS (`sm_students`) — who a charge is *for*.
 *
 * The student never pays. `parent_id` points at the {@see Guardian} row that
 * does, and that is the **only** student↔payer edge in the LMS database:
 * there is no pivot table and no second guardian slot, so a student having
 * more than one payer (a parent plus a sponsor) is something only
 * `pas_contact_students` can express.
 *
 * **Do not read `class_id` / `section_id` from this table.** Both are NULL on
 * live rows; the real enrolment lives in `student_records`, one row per
 * student per session, which is also what `sm_fees_assigns.record_id` points
 * at. This model deliberately exposes no enrolment accessor, because nothing
 * in this slice needs one.
 *
 * No `SoftDeletes` — `sm_students` has no `deleted_at`, and the trait would
 * fight {@see ReadOnlyModel::delete()} anyway.
 *
 * Reading this table is an amendment to `rules/PLAN.md` §2.
 *
 * @property int $id
 * @property ?string $first_name
 * @property ?string $last_name
 * @property ?string $full_name
 * @property ?string $email
 * @property ?int $parent_id
 * @property bool $active_status
 */
final class Student extends ReadOnlyModel
{
    protected $table = 'sm_students';

    public $timestamps = true;

    protected $casts = [
        'active_status' => 'boolean',
        'date_of_birth' => 'date',
        'admission_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'parent_id');
    }

    /**
     * `full_name` is denormalised in the LMS and populated on every row, but
     * fall back rather than render a blank name on a document.
     */
    public function displayName(): string
    {
        if (is_string($this->full_name) && trim($this->full_name) !== '') {
            return trim($this->full_name);
        }

        $parts = array_filter([$this->first_name, $this->last_name], static fn ($p): bool => is_string($p) && trim($p) !== '');

        return $parts === [] ? ('Student #'.$this->getKey()) : implode(' ', array_map('trim', $parts));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_status', 1);
    }
}
