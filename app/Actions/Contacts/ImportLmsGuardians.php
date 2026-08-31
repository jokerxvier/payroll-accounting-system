<?php

declare(strict_types=1);

namespace App\Actions\Contacts;

use App\Models\Lms\Guardian;
use App\Models\Lms\Student;
use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Brings the school's parents and guardians into the contact register as
 * billing counterparties, linked to the students they pay for.
 *
 * **The rule this class exists to hold: one payer, one contact.** A parent
 * with three children gets one contact and three links — never three copies of
 * the same person. Duplicating a payer scatters a family's receivable across
 * several counterparties, breaks their statement, and counts them repeatedly in
 * Aged Receivables, so every path here either finds the existing contact or
 * creates exactly one.
 *
 * De-duplication runs in confidence order:
 *
 *   1. `(school_id, lms_parent_id)` — the source row's own id. The only key
 *      that is a fact rather than a guess, and what makes re-running the
 *      import a no-op.
 *   2. `email`, then 3. `phone` — heuristics, needed because the LMS does not
 *      guarantee siblings share a parent row. The demo data has
 *      `sm_students.id == sm_parents.id` with consecutive user ids, which is
 *      the signature of a parent record created per admission; assuming the
 *      LMS already deduped would produce exactly the duplication above.
 *
 * **Ambiguity is refused, never guessed.** Two existing contacts sharing one
 * email is a row error: merging the wrong two people into one payer is far
 * harder to unpick than importing nothing and being told why.
 *
 * `preview()` writes nothing — it is what the confirm screen renders. `apply()`
 * is the only method that touches the database.
 */
final class ImportLmsGuardians
{
    public const ACTION_CREATE = 'create';

    public const ACTION_LINK = 'link';

    public const ACTION_UNCHANGED = 'unchanged';

    /**
     * What importing would do, without doing any of it.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(): array
    {
        $students = Student::query()->active()->get();

        if ($students->isEmpty()) {
            return [];
        }

        $guardians = Guardian::query()
            ->whereIn('id', $students->pluck('parent_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        // Group students by their payer first, so a parent with three children
        // is considered once with three students rather than three times.
        $byGuardian = $students
            ->filter(fn (Student $s): bool => $s->parent_id !== null && $guardians->has($s->parent_id))
            ->groupBy(fn (Student $s): int => (int) $s->parent_id);

        $rows = [];

        foreach ($byGuardian as $parentId => $theirStudents) {
            $guardian = $guardians->get($parentId);

            if (! $guardian instanceof Guardian) {
                continue;
            }

            $rows[] = $this->describe((int) $parentId, $guardian, $theirStudents);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /**
     * Apply a previewed set. Rows carrying errors are skipped, not guessed at.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{contacts_created: int, contacts_linked: int, students_linked: int}
     */
    public function apply(array $rows): array
    {
        $created = 0;
        $linked = 0;
        $studentsLinked = 0;

        DB::transaction(function () use ($rows, &$created, &$linked, &$studentsLinked): void {
            foreach ($rows as $row) {
                if (! empty($row['errors']) || $row['action'] === self::ACTION_UNCHANGED) {
                    continue;
                }

                $contact = $this->resolveExisting(
                    (int) $row['lms_parent_id'],
                    $row['email'] ?? null,
                    $row['phone'] ?? null,
                );

                if ($contact === null) {
                    $contact = Contact::create([
                        'code' => $this->uniqueCode((int) $row['lms_parent_id']),
                        'name' => (string) $row['name'],
                        'is_customer' => true,
                        'is_supplier' => false,
                        'email' => $row['email'] ?? null,
                        'phone' => $row['phone'] ?? null,
                        'address' => $row['address'] ?? null,
                        'lms_parent_id' => (int) $row['lms_parent_id'],
                        'is_active' => true,
                    ]);

                    $created++;
                } else {
                    // Found by email or phone rather than by id — claim the
                    // pointer so the next run matches on the certain key.
                    if ($contact->lms_parent_id === null) {
                        $contact->forceFill(['lms_parent_id' => (int) $row['lms_parent_id']])->save();
                    }

                    $linked++;
                }

                /** @var list<array<string, mixed>> $students */
                $students = $row['students'] ?? [];

                foreach ($students as $student) {
                    $studentsLinked += $this->linkStudent(
                        $contact,
                        (int) $student['lms_student_id'],
                        (string) $student['name'],
                        $row['relationship'] ?? null,
                    );
                }
            }
        });

        return [
            'contacts_created' => $created,
            'contacts_linked' => $linked,
            'students_linked' => $studentsLinked,
        ];
    }

    /**
     * @param  Collection<int, Student>  $students
     * @return array<string, mixed>
     */
    private function describe(int $parentId, Guardian $guardian, Collection $students): array
    {
        $name = $guardian->billingName();
        $email = $guardian->billingEmail();
        $phone = $guardian->billingPhone();

        $errors = [];

        if ($name === null) {
            $errors[] = 'This parent record has no name on it, so there is nobody to bill.';
        }

        $existing = null;

        try {
            $existing = $this->resolveExisting($parentId, $email, $phone);
        } catch (AmbiguousContactMatch $e) {
            $errors[] = $e->getMessage();
        }

        /** @var list<array<string, mixed>> $studentRows */
        $studentRows = array_values($students->map(fn (Student $s): array => [
            'lms_student_id' => (int) $s->getKey(),
            'name' => $s->displayName(),
        ])->all());

        $action = match (true) {
            $errors !== [] => self::ACTION_UNCHANGED,
            $existing === null => self::ACTION_CREATE,
            $this->allStudentsAlreadyLinked($existing, $studentRows) => self::ACTION_UNCHANGED,
            default => self::ACTION_LINK,
        };

        return [
            'lms_parent_id' => $parentId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $guardian->billingAddress(),
            'relationship' => $guardian->guardians_relation,
            'students' => $studentRows,
            'existing_contact_id' => $existing?->getKey(),
            'existing_contact_name' => $existing?->name,
            'action' => $action,
            'errors' => $errors,
        ];
    }

    /**
     * The contact this parent already is, if any.
     *
     * @throws AmbiguousContactMatch When a heuristic matches more than one.
     */
    private function resolveExisting(int $parentId, ?string $email, ?string $phone): ?Contact
    {
        $byId = Contact::query()->where('lms_parent_id', $parentId)->first();

        if ($byId !== null) {
            return $byId;
        }

        foreach ([['email', $email], ['phone', $phone]] as [$column, $value]) {
            if ($value === null || $value === '') {
                continue;
            }

            // Deliberately NOT filtered to contacts with no `lms_parent_id`.
            // The case this exists for is two LMS parent rows describing one
            // person — the second row must find the contact the first row
            // already created and claimed, or the sibling gets a duplicate
            // payer, which is the outcome the whole class is built to avoid.
            $matches = Contact::query()
                ->where($column, $value)
                ->get();

            if ($matches->count() > 1) {
                throw new AmbiguousContactMatch(sprintf(
                    '%d contacts already share the %s %s. Merge or correct them first — picking one automatically could bill the wrong person.',
                    $matches->count(),
                    $column,
                    $value,
                ));
            }

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $students
     */
    private function allStudentsAlreadyLinked(Contact $contact, array $students): bool
    {
        $linked = ContactStudent::query()
            ->where('contact_id', $contact->getKey())
            ->pluck('lms_student_id')
            ->all();

        foreach ($students as $student) {
            if (! in_array((int) $student['lms_student_id'], array_map('intval', $linked), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return int 1 if a new link was written, 0 if it already existed.
     */
    private function linkStudent(Contact $contact, int $lmsStudentId, string $studentName, ?string $relationship): int
    {
        $existing = ContactStudent::query()
            ->where('contact_id', $contact->getKey())
            ->forStudent($lmsStudentId)
            ->first();

        if ($existing !== null) {
            return 0;
        }

        // The first payer linked to a student becomes the primary. A second
        // one — a sponsor, a separated parent — is linked but not promoted;
        // changing who pays by default is a decision for a person, not an
        // import.
        $hasPrimary = ContactStudent::query()
            ->forStudent($lmsStudentId)
            ->primary()
            ->exists();

        ContactStudent::create([
            'contact_id' => $contact->getKey(),
            'lms_student_id' => $lmsStudentId,
            'student_name' => $studentName,
            'relationship' => $relationship,
            'is_primary_payer' => ! $hasPrimary,
        ]);

        return 1;
    }

    /**
     * `PAR-{id}`, with a numeric suffix if a human already took that code.
     */
    private function uniqueCode(int $parentId): string
    {
        $base = 'PAR-'.$parentId;
        $code = $base;
        $suffix = 1;

        while (Contact::query()->where('code', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }
}
