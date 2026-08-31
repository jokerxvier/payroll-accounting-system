<?php

declare(strict_types=1);

namespace App\Models\Lms;

use Illuminate\Database\Eloquent\Builder;

/**
 * A family's parent/guardian record in the LMS (`sm_parents`).
 *
 * **Named `Guardian`, not `Parent`, because `parent` is a PHP reserved word**
 * — `class Parent` is a fatal parse error. `Guardian` also reads truer to the
 * table's content: the `guardians_*` columns are the ones a school actually
 * bills, and the father/mother columns are contact details rather than
 * separate people.
 *
 * One row holds one father, one mother and one guardian **as text columns**,
 * not as entities. The LMS models no billing role at all — no payer
 * designation, no sponsor, no second guardian slot — so "who pays for this
 * student" is expressed on our side in `pas_contact_students`, not here.
 *
 * `is_guardian` is unreliable and must not be branched on: Infix copies the
 * father's details into the `guardians_*` columns when the operator leaves it
 * unchecked, and it is NULL on live rows. To tell whether the guardian is a
 * genuinely different person, compare `guardians_name` against
 * `fathers_name`/`mothers_name`.
 *
 * Reading this table is an amendment to `rules/PLAN.md` §2, which previously
 * limited LMS reads to staff and identity tables.
 *
 * @property int $id
 * @property ?string $fathers_name
 * @property ?string $fathers_mobile
 * @property ?string $mothers_name
 * @property ?string $mothers_mobile
 * @property ?string $guardians_name
 * @property ?string $guardians_mobile
 * @property ?string $guardians_email
 * @property ?string $guardians_relation
 * @property ?string $guardians_address
 * @property bool $is_guardian
 * @property bool $active_status
 */
final class Guardian extends ReadOnlyModel
{
    protected $table = 'sm_parents';

    public $timestamps = true;

    protected $casts = [
        'active_status' => 'boolean',
        // int NULL in the schema; the cast turns NULL into false, which is
        // the right default for a flag documented as unreliable.
        'is_guardian' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The best available name for the person who pays.
     *
     * Falls back through the columns in the order the LMS fills them: the
     * guardian is set on every admission (copied from the father when not
     * separately supplied), so it is almost always right, but a row that
     * predates that behaviour can still be billed.
     */
    public function billingName(): ?string
    {
        foreach ([$this->guardians_name, $this->fathers_name, $this->mothers_name] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * `guardians_email` is the only email column on this table — neither the
     * father nor the mother has one.
     */
    public function billingEmail(): ?string
    {
        $email = $this->guardians_email;

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }

    public function billingPhone(): ?string
    {
        foreach ([$this->guardians_mobile, $this->fathers_mobile, $this->mothers_mobile] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    public function billingAddress(): ?string
    {
        $address = $this->guardians_address;

        return is_string($address) && trim($address) !== '' ? trim($address) : null;
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
