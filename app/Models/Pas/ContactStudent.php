<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\Models\Lms\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payer's responsibility for one student.
 *
 * The whole billing relationship lives here, because the LMS holds none of it:
 * `sm_students.parent_id` is a single edge to a single row, with no payer
 * designation, no sponsor concept and no second guardian slot. "Select the
 * student, load the primary billing parent, allow another linked guardian or
 * sponsor" is expressed entirely by these rows.
 *
 * `lms_student_id` is only meaningful beside `school_id` — each school has its
 * own LMS database and the ids repeat, so student 29 exists in every tenant.
 * Every query here is composite for that reason, and `BelongsToTenant` handles
 * the `school_id` half automatically.
 *
 * @property int $id
 * @property int $school_id
 * @property int $contact_id
 * @property int $lms_student_id
 * @property string $student_name
 * @property ?string $relationship
 * @property bool $is_primary_payer
 */
final class ContactStudent extends Model
{
    use Auditable;
    use BelongsToTenant;

    protected $table = 'pas_contact_students';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'contact_id',
        'lms_student_id',
        'student_name',
        'relationship',
        'is_primary_payer',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'contact_id' => 'integer',
            'lms_student_id' => 'integer',
            'is_primary_payer' => 'boolean',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The LMS record this link points at, or null if it has since gone.
     *
     * Not an Eloquent relation: the target is on a different connection, and
     * a `belongsTo` across connections silently returns nothing rather than
     * failing, which is the worst of both. An explicit lookup makes the
     * cross-database hop visible at every call site.
     */
    public function resolveStudent(): ?Student
    {
        return Student::query()->find($this->lms_student_id);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForStudent(Builder $query, int $lmsStudentId): Builder
    {
        return $query->where('lms_student_id', $lmsStudentId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary_payer', true);
    }
}
